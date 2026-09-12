<?php

namespace Tests\Feature;

use App\Jobs\SendTicketReply;
use App\Models\Automation;
use App\Models\CannedReply;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Services\DeliveryReport;
use App\Services\IncomingMail;
use App\Services\MailSafety;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Webklex\PHPIMAP\Message as ImapMessage;

class MailSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function outgoing(?Mailbox $mailbox = null): Message
    {
        $mailbox ??= Mailbox::factory()->create(['sending_enabled' => true, 'incoming_enabled' => true, 'smtp_host' => 'smtp.example.com']);
        $ticket = Ticket::factory()->create(['mailbox_id' => $mailbox->id]);

        return Message::factory()->create(['ticket_id' => $ticket->id, 'kind' => 'outbound', ...app(MailSafety::class)->prepare($mailbox)]);
    }

    private function failure(?Message $message = null): Message
    {
        $message ??= $this->outgoing();
        $this->assertTrue(app(MailSafety::class)->begin($message));
        app(MailSafety::class)->recordFailure($message->attempt_id, 'smtp', 'SMTP delivery failed.');

        return $message->fresh();
    }

    private function report(string $originalId, string $action = 'failed', string $status = '5.1.1', string $encoding = '7bit', string $originalType = 'text/rfc822-headers', string $references = ''): ImapMessage
    {
        $delivery = "Reporting-MTA: dns; mx.example.com\r\n\r\nFinal-Recipient: rfc822; customer@example.com\r\nAction: {$action}\r\nStatus: {$status}\r\nDiagnostic-Code: smtp; 550 Unknown user\r\n";
        if ($encoding === 'base64') {
            $delivery = base64_encode($delivery);
        }
        $mime = "From: Mail Delivery Subsystem <mailer-daemon@example.com>\r\nTo: support@example.com\r\nDate: Fri, 11 Sep 2026 12:00:00 +0000\r\nMessage-ID: <report@example.com>\r\nReferences: {$references}\r\nSubject: Delivery status notification\r\nMIME-Version: 1.0\r\nContent-Type: multipart/report; report-type=delivery-status; boundary=\"dsn-boundary\"\r\n\r\n".
            "--dsn-boundary\r\nContent-Type: text/plain\r\n\r\nYour email could not be delivered.\r\n".
            "--dsn-boundary\r\nContent-Type: message/delivery-status\r\nContent-Transfer-Encoding: {$encoding}\r\n\r\n{$delivery}\r\n".
            "--dsn-boundary\r\nContent-Type: {$originalType}\r\n\r\nMessage-ID: <{$originalId}>\r\nFrom: support@example.com\r\nTo: customer@example.com\r\n\r\n".
            "--dsn-boundary--\r\n";

        return ImapMessage::fromString($mime);
    }

    public function test_threshold_stops_all_accounts_and_persists_after_the_window_expires(): void
    {
        $waiting = $this->outgoing();
        $safety = app(MailSafety::class);
        for ($i = 0; $i < 4; $i++) {
            $this->failure();
        }
        $this->assertFalse($safety->status()['paused']);
        $this->failure();
        $this->assertTrue($safety->status()['paused']);
        $this->assertSame('held', $waiting->fresh()->delivery);
        Mail::shouldReceive('build')->never();
        (new SendTicketReply($waiting))->handle();
        $this->travel(2)->days();
        $this->assertTrue(app(MailSafety::class)->status()['paused']);
        $this->assertSame(0, $safety->status()['recent_failures']);
        $this->assertTrue($waiting->ticket->mailbox->fresh()->incoming_enabled);
        $this->assertTrue($waiting->ticket->mailbox->fresh()->sending_enabled);
    }

    public function test_smtp_exception_and_failed_callback_count_once_and_trigger_the_stop(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        app(MailSafety::class)->configure(1, 15, $admin->id);
        $message = $this->outgoing();
        Mail::shouldReceive('build')->once()->andThrow(new \RuntimeException('secret-password'));
        $job = new SendTicketReply($message);
        try {
            $job->handle();
        } catch (\RuntimeException $exception) {
            $job->failed($exception);
        }
        $this->assertTrue(app(MailSafety::class)->status()['paused']);
        $this->assertSame(1, app(MailSafety::class)->status()['recent_failures']);
        $this->assertStringNotContainsString('secret-password', $message->fresh()->delivery_error);
    }

    public function test_rolling_window_ignores_old_failures_and_settings_cannot_clear_a_stop(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->freezeTime();
        $this->failure();
        $this->travel(16)->minutes();
        for ($i = 0; $i < 4; $i++) {
            $this->failure();
        }
        $this->assertFalse(app(MailSafety::class)->status()['paused']);
        $this->actingAs($admin)->putJson('/api/v1/sending-safety', ['threshold' => 4, 'window_minutes' => 15])->assertOk()->assertJsonPath('paused', true);
        $this->putJson('/api/v1/sending-safety', ['threshold' => 100, 'window_minutes' => 15])->assertOk()->assertJsonPath('paused', true);
        $this->putJson('/api/v1/sending-safety', ['threshold' => 0, 'window_minutes' => 15])->assertUnprocessable();
    }

    public function test_incoming_mail_and_notes_continue_but_automatic_and_manual_replies_are_held(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $mailbox = Mailbox::factory()->create(['sending_enabled' => true, 'incoming_enabled' => true, 'smtp_host' => 'smtp.example.com']);
        app(MailSafety::class)->manualPause($admin->id);
        $reply = CannedReply::factory()->create();
        Automation::factory()->create(['enabled' => true, 'trigger' => 'ticket.created', 'conditions' => [], 'actions' => ['reply_id' => $reply->id]]);
        $ticket = app(IncomingMail::class)->import($mailbox, ['external_id' => 'incoming@example.com', 'from_email' => 'customer@example.com', 'subject' => 'Please help', 'body' => 'My question']);
        $this->assertNotNull($ticket);
        $this->assertTrue($ticket->unread);
        $this->assertSame('held', $ticket->messages()->where('kind', 'outbound')->first()->delivery);
        $this->actingAs($admin)->postJson('/api/v1/tickets/'.$ticket->id.'/messages', ['body' => 'Manual reply'])->assertOk();
        $manual = $ticket->messages()->reorder()->latest('id')->first();
        $this->assertSame('held', $manual->delivery);
        $this->postJson('/api/v1/messages/'.$manual->id.'/retry')->assertConflict();
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', ['body' => 'Private investigation', 'private' => true])->assertOk();
        $this->assertSame('note', $ticket->messages()->reorder()->latest('id')->first()->kind);
        $this->assertNull($ticket->messages()->reorder()->latest('id')->first()->delivery);
        $this->getJson('/api/v1/tickets?view=undelivered')->assertJsonPath('total', 1);
        Queue::assertNothingPushed();
    }

    public function test_only_reviewed_admin_resume_is_allowed_and_does_not_release_backlog(): void
    {
        Queue::fake();
        $waiting = $this->outgoing();
        $agent = User::factory()->create(['role' => 'agent']);
        $admin = User::factory()->create(['role' => 'admin']);
        app(MailSafety::class)->manualPause($admin->id);
        $this->actingAs($agent)->postJson('/api/v1/sending-safety/resume', ['reviewed' => true, 'review' => 'Fixed mailbox settings', 'epoch' => 0])->assertForbidden();
        $this->putJson('/api/v1/sending-safety', ['threshold' => 50, 'window_minutes' => 15])->assertForbidden();
        $this->postJson('/api/v1/sending-safety/pause')->assertForbidden();
        $this->getJson('/api/v1/sending-safety?review=1')->assertOk()->assertJsonMissingPath('failures');
        $this->actingAs($admin)->postJson('/api/v1/sending-safety/resume', ['epoch' => 0])->assertUnprocessable();
        $this->postJson('/api/v1/sending-safety/resume', ['reviewed' => true, 'review' => 'Fixed mailbox settings', 'epoch' => 0])->assertOk()->assertJsonPath('paused', false);
        $this->assertSame('held', $waiting->fresh()->delivery);
        Queue::assertNothingPushed();
        Mail::shouldReceive('build')->never();
        (new SendTicketReply($waiting))->handle();
        $this->postJson('/api/v1/messages/'.$waiting->id.'/retry')->assertAccepted();
        Queue::assertPushed(SendTicketReply::class, 1);
        $this->assertSame(1, (int) $waiting->fresh()->sending_epoch);
        app(MailSafety::class)->manualPause($admin->id);
        $this->postJson('/api/v1/sending-safety/resume', ['reviewed' => true, 'review' => 'Stale review', 'epoch' => 0])->assertConflict();
    }

    public function test_old_jobs_cannot_cross_a_pause_even_if_they_missed_the_bulk_hold_update(): void
    {
        $message = $this->outgoing();
        $admin = User::factory()->create(['role' => 'admin']);
        $safety = app(MailSafety::class);
        $safety->manualPause($admin->id);
        $safety->resume($admin->id, 'Checked the provider', 0);
        $message->update(['delivery' => 'queued', 'sending_epoch' => 0]);
        Mail::shouldReceive('build')->never();
        (new SendTicketReply($message))->handle();
        $this->assertSame('held', $message->fresh()->delivery);
    }

    public function test_reactivation_starts_a_new_window_and_each_reviewed_attempt_can_fail_again(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $safety = app(MailSafety::class);
        $safety->configure(1, 15, $admin->id);
        $message = $this->failure();
        $firstAttempt = $message->attempt_id;
        $safety->resume($admin->id, 'Corrected recipient', 0);
        $this->assertSame(0, $safety->status()['recent_failures']);
        $safety->recordFailure($firstAttempt, 'bounce', 'Duplicate report');
        $this->assertFalse($safety->status()['paused']);
        $message->update($safety->prepare($message->ticket->mailbox));
        $this->failure($message);
        $this->assertNotSame($firstAttempt, $message->fresh()->attempt_id);
        $this->assertTrue($safety->status()['paused']);
        $this->assertDatabaseCount('mail_delivery_attempts', 2);
    }

    public function test_structured_bounces_are_correlated_and_duplicate_reports_count_once(): void
    {
        $message = $this->outgoing();
        $safety = app(MailSafety::class);
        $safety->begin($message);
        $message->update(['delivery' => 'sent']);
        $report = $this->report($message->external_id);
        $parser = app(DeliveryReport::class);
        $this->assertTrue($parser->consume($message->ticket->mailbox, $report));
        $this->assertSame('failed', $message->fresh()->delivery);
        $this->assertStringContainsString('5.1.1', $message->fresh()->delivery_error);
        $this->assertTrue($parser->consume($message->ticket->mailbox, $report));
        $this->assertSame(1, $safety->status()['recent_failures']);
        $this->assertDatabaseCount('tickets', 1);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_delays_successes_unknown_ids_and_wrong_mailboxes_do_not_count_as_bounces(): void
    {
        $message = $this->outgoing();
        $safety = app(MailSafety::class);
        $safety->begin($message);
        $message->update(['delivery' => 'sent']);
        $parser = app(DeliveryReport::class);
        $parser->consume($message->ticket->mailbox, $this->report($message->external_id, 'delayed', '4.2.0'));
        $parser->consume($message->ticket->mailbox, $this->report($message->external_id, 'delivered', '2.0.0'));
        $this->assertFalse($parser->consume($message->ticket->mailbox, $this->report('unknown@example.com')));
        $this->assertFalse($parser->consume(Mailbox::factory()->create(), $this->report($message->external_id)));
        $this->assertSame(0, $safety->status()['recent_failures']);
        $this->assertSame('sent', $message->fresh()->delivery);
        $mail = ImapMessage::fromString("From: customer@example.com\r\nDate: Fri, 11 Sep 2026 12:00:00 +0000\r\nSubject: Delivery failed\r\nContent-Type: text/plain\r\n\r\nAction: failed\r\nStatus: 5.1.1\r\nMessage-ID: <{$message->external_id}>\r\n");
        $this->assertFalse($parser->consume($message->ticket->mailbox, $mail));
    }

    public function test_encoded_bounces_trigger_global_pause_and_old_attempt_reports_do_not_overwrite_retries(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $safety = app(MailSafety::class);
        $safety->configure(1, 15, $admin->id);
        $message = $this->outgoing();
        $safety->begin($message);
        $oldId = $message->external_id;
        $message->update(['delivery' => 'sent']);
        $parser = app(DeliveryReport::class);
        $this->assertTrue($parser->consume($message->ticket->mailbox, $this->report($oldId, encoding: 'base64')));
        $this->assertTrue($safety->status()['paused']);
        $safety->resume($admin->id, 'Fixed the recipient', 0);
        $message->update($safety->prepare($message->ticket->mailbox));
        $safety->begin($message);
        $message->update(['delivery' => 'sent']);
        $parser->consume($message->ticket->mailbox, $this->report($oldId));
        $this->assertSame('sent', $message->fresh()->delivery);
        $this->assertFalse($safety->status()['paused']);
    }

    public function test_an_attempt_can_only_be_claimed_once_and_timeout_is_recorded(): void
    {
        $message = $this->outgoing();
        $otherWorkerCopy = Message::findOrFail($message->id);
        $this->assertTrue(app(MailSafety::class)->begin($message));
        $this->assertFalse(app(MailSafety::class)->begin($otherWorkerCopy));
        $this->assertDatabaseCount('mail_delivery_attempts', 1);
        (new SendTicketReply($message))->failed(new \RuntimeException('Timed out'));
        $this->assertSame('failed', $message->fresh()->delivery);
        $this->assertSame(1, app(MailSafety::class)->status()['recent_failures']);
    }

    public function test_final_expired_delivery_with_attached_original_email_counts_as_a_bounce(): void
    {
        $message = $this->outgoing();
        app(MailSafety::class)->begin($message);
        $message->update(['delivery' => 'sent']);
        $this->assertTrue(app(DeliveryReport::class)->consume($message->ticket->mailbox, $this->report($message->external_id, 'failed', '4.4.7', originalType: 'message/rfc822')));
        $this->assertSame(1, app(MailSafety::class)->status()['recent_failures']);
        $this->assertSame('failed', $message->fresh()->delivery);
    }

    public function test_thread_references_do_not_count_other_replies_as_bounced(): void
    {
        $earlier = $this->outgoing();
        $latest = $this->outgoing($earlier->ticket->mailbox);
        foreach ([$earlier, $latest] as $message) {
            app(MailSafety::class)->begin($message);
            $message->update(['delivery' => 'sent']);
        }
        $report = $this->report($latest->external_id, references: '<'.$earlier->external_id.'> <'.$latest->external_id.'>');
        $this->assertTrue(app(DeliveryReport::class)->consume($latest->ticket->mailbox, $report));
        $this->assertSame(1, app(MailSafety::class)->status()['recent_failures']);
        $this->assertSame('sent', $earlier->fresh()->delivery);
        $this->assertSame('failed', $latest->fresh()->delivery);
    }
}
