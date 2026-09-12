<?php

namespace Tests\Feature;

use App\Jobs\SendPasswordRecovery;
use App\Jobs\SendTicketReply;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkspaceSetting;
use App\Services\DeliveryReport;
use App\Services\DeliveryTracking;
use App\Services\IncomingMail;
use App\Services\MailSafety;
use App\Services\SenderPolicy;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class MailPolicyAndApiTest extends TestCase
{
    use RefreshDatabase;

    private function outgoing(array $ticketData = []): Message
    {
        $box = Mailbox::factory()->create(['sending_enabled' => true, 'smtp_host' => 'smtp.example.com']);
        $ticket = Ticket::factory()->create(['mailbox_id' => $box->id, ...$ticketData]);

        return Message::factory()->create(['ticket_id' => $ticket->id, 'kind' => 'outbound', ...app(MailSafety::class)->prepare($box)]);
    }

    private function key(array $scopes = ['tickets:create']): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $box = Mailbox::factory()->create();
        $key = $this->actingAs($admin)->postJson('/api/v1/api-keys', ['name' => 'Application', 'mailbox_id' => $box->id, 'scopes' => $scopes])->assertCreated()->json();

        return [$key, $box, $admin];
    }

    public function test_domain_and_exact_sender_rules_classify_incoming_without_overriding_suppression(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $box = Mailbox::factory()->create();
        $this->actingAs($admin)->postJson('/api/v1/mail-policy/senders', ['kind' => 'domain', 'value' => 'EXAMPLE.com', 'action' => 'blocked', 'include_subdomains' => true])->assertOk();
        $policy = app(SenderPolicy::class);
        $this->assertSame('blocked', $policy->classification('customer@sub.example.com'));
        $this->assertNull($policy->classification('customer@badexample.com'));
        $ticket = app(IncomingMail::class)->import($box, ['external_id' => 'blocked@example.com', 'from_email' => 'customer@example.com', 'subject' => 'Hello', 'body' => 'Still received']);
        $this->assertSame('spam', $ticket->folder);
        $this->assertSame(1, $ticket->messages()->count());
        $this->postJson('/api/v1/mail-policy/senders', ['kind' => 'email', 'value' => 'customer@example.com', 'action' => 'trusted', 'include_subdomains' => false])->assertOk();
        $this->assertSame('trusted', $policy->classification('customer@example.com'));
        $policy->suppress('CUSTOMER@example.com', 'complaint');
        $this->assertStringContainsString('suppressed', $policy->restriction('customer@example.com'));
        $incoming = app(IncomingMail::class)->import($box, ['external_id' => 'trusted@example.com', 'from_email' => 'customer@example.com', 'subject' => '[#'.$ticket->id.']', 'body' => 'Still received again']);
        $this->assertSame('inbox', $incoming->folder);
        $this->assertNotNull($policy->restriction('customer@example.com'));
    }

    public function test_suppression_of_requester_or_cc_is_enforced_at_worker_time(): void
    {
        $message = $this->outgoing(['cc' => ['cc@example.com']]);
        app(SenderPolicy::class)->suppress('cc@example.com', 'opt_out');
        Mail::shouldReceive('build')->never();
        (new SendTicketReply($message))->handle();
        $this->assertSame('suppressed', $message->fresh()->delivery);
        $this->assertDatabaseCount('mail_delivery_attempts', 0);
        $this->assertFalse(app(MailSafety::class)->status()['paused']);
    }

    public function test_delivery_tracking_requires_all_recipients_and_does_not_overwrite_failure(): void
    {
        $message = $this->outgoing(['requester_email' => 'to@example.com', 'cc' => ['cc@example.com']]);
        app(MailSafety::class)->begin($message);
        $message->update(['delivery' => 'sent']);
        $tracking = app(DeliveryTracking::class);
        $tracking->record($message->attempt_id, 'delivered', 'event-1', 'to@example.com');
        $this->assertSame('sent', $message->fresh()->delivery);
        $tracking->record($message->attempt_id, 'delivered', 'event-2', 'cc@example.com');
        $this->assertSame('delivered', $message->fresh()->delivery);
        $tracking->record($message->attempt_id, 'failed', 'event-3', 'cc@example.com', 'Mailbox full');
        $tracking->record($message->attempt_id, 'failed', 'event-3', 'cc@example.com', 'Mailbox full');
        $tracking->record($message->attempt_id, 'opened', 'event-4', 'to@example.com');
        $tracking->record($message->attempt_id, 'delivered', 'event-5', 'cc@example.com');
        $this->assertSame('failed', $message->fresh()->delivery);
        $this->assertSame(1, app(MailSafety::class)->status()['recent_failures']);
        $this->assertDatabaseCount('delivery_events', 5);
        $this->assertNotNull(DB::table('mail_delivery_attempts')->first()->opened_at);
    }

    public function test_signed_pixel_and_confirmed_opt_out_are_idempotent_and_get_does_not_unsubscribe(): void
    {
        $message = $this->outgoing();
        app(MailSafety::class)->begin($message);
        $pixel = URL::signedRoute('mail.open', ['attempt' => $message->attempt_id]);
        $this->get($pixel)->assertOk()->assertHeader('Content-Type', 'image/gif');
        $this->get($pixel)->assertOk();
        $this->assertDatabaseCount('delivery_events', 1);
        $this->get('/mail/open/'.$message->attempt_id)->assertForbidden();
        $url = URL::signedRoute('mail.optout', ['attempt' => $message->attempt_id]);
        $this->get($url)->assertOk()->assertSee('Stop email to this address');
        $this->assertDatabaseCount('recipient_suppressions', 0);
        $this->post($url)->assertOk()->assertSee('Email stopped');
        $this->post($url)->assertOk();
        $this->assertDatabaseCount('recipient_suppressions', 1);
        $this->assertSame('opt_out', DB::table('recipient_suppressions')->first()->reason);
    }

    public function test_api_keys_create_tickets_idempotently_with_registration_closed_and_are_revocable(): void
    {
        Queue::fake();
        [$key, $box] = $this->key();
        WorkspaceSetting::updateOrCreate(['key' => 'general'], ['value' => ['registration_enabled' => false]]);
        $stored = DB::table('api_keys')->where('id', $key['id'])->first();
        $this->assertSame(hash('sha256', $key['token']), $stored->token_hash);
        $this->getJson('/api/v1/api-keys')->assertJsonMissingPath('keys.0.token_hash')->assertDontSee($key['token']);
        $data = ['subject' => 'App request', 'requester_email' => 'customer@example.com', 'body' => 'Details'];
        $headers = ['Authorization' => 'Bearer '.$key['token'], 'Idempotency-Key' => 'order-123'];
        $first = $this->postJson('/api/v1/external/tickets', $data, $headers)->assertCreated()->assertJsonPath('replayed', false)->json('ticket.id');
        $this->postJson('/api/v1/external/tickets', $data, $headers)->assertOk()->assertJsonPath('ticket.id', $first)->assertJsonPath('replayed', true);
        $this->postJson('/api/v1/external/tickets', [...$data, 'body' => 'Different'], $headers)->assertConflict();
        $this->assertDatabaseCount('tickets', 1);
        $this->assertDatabaseHas('tickets', ['id' => $first, 'mailbox_id' => $box->id, 'source' => 'Webhook']);
        $this->postJson('/api/v1/external/tickets', $data)->assertUnauthorized();
        $this->deleteJson('/api/v1/api-keys/'.$key['id'])->assertOk();
        $this->postJson('/api/v1/external/tickets', $data, $headers)->assertUnauthorized();
    }

    public function test_api_scope_and_mailbox_are_enforced_and_complaints_suppress_only_actual_recipients(): void
    {
        [$key, $box] = $this->key(['delivery:write']);
        $ticket = Ticket::factory()->create(['mailbox_id' => $box->id, 'requester_email' => 'customer@example.com']);
        $box->update(['sending_enabled' => true, 'smtp_host' => 'smtp.example.com']);
        $message = Message::factory()->create(['ticket_id' => $ticket->id, 'kind' => 'outbound', 'delivery' => 'queued']);
        app(MailSafety::class)->begin($message);
        $headers = ['Authorization' => 'Bearer '.$key['token']];
        $this->postJson('/api/v1/external/tickets', [], $headers)->assertForbidden();
        $data = ['event_id' => 'complaint-1', 'message_id' => $message->external_id, 'recipient' => 'unrelated@example.com', 'type' => 'complaint'];
        $this->postJson('/api/v1/external/delivery-events', $data, $headers)->assertUnprocessable();
        $data['recipient'] = 'customer@example.com';
        $this->postJson('/api/v1/external/delivery-events', $data, $headers)->assertOk();
        $this->postJson('/api/v1/external/delivery-events', $data, $headers)->assertOk();
        $this->assertDatabaseCount('recipient_suppressions', 1);
        $this->assertDatabaseCount('delivery_events', 1);
        $other = $this->outgoing();
        app(MailSafety::class)->begin($other);
        $this->postJson('/api/v1/external/delivery-events', [...$data, 'event_id' => 'event-other', 'message_id' => $other->external_id], $headers)->assertNotFound();
    }

    public function test_recovery_is_generic_works_with_registration_disabled_and_tokens_are_single_use(): void
    {
        Queue::fake();
        $user = User::factory()->create(['email' => 'admin@example.com', 'role' => 'admin']);
        $box = Mailbox::factory()->create(['sending_enabled' => true, 'smtp_host' => 'smtp.example.com']);
        WorkspaceSetting::create(['key' => 'general', 'value' => ['registration_enabled' => false]]);
        WorkspaceSetting::create(['key' => 'mail_policy', 'value' => ['recovery_mailbox_id' => $box->id]]);
        $known = $this->postJson('/api/v1/forgot-password', ['email' => $user->email])->assertOk()->json();
        $unknown = $this->postJson('/api/v1/forgot-password', ['email' => 'unknown@example.com'])->assertOk()->json();
        $this->assertSame($known, $unknown);
        Queue::assertPushed(SendPasswordRecovery::class, 1);
        $job = Queue::pushed(SendPasswordRecovery::class)->first();
        $this->assertInstanceOf(ShouldBeEncrypted::class, $job);
        $payload = ['email' => $user->email, 'token' => $job->token, 'password' => 'A-new-secure-password-42', 'password_confirmation' => 'A-new-secure-password-42'];
        $this->postJson('/api/v1/reset-password', [...$payload, 'token' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/v1/reset-password', $payload)->assertOk();
        $this->assertTrue(Hash::check($payload['password'], $user->fresh()->password));
        $this->postJson('/api/v1/reset-password', $payload)->assertUnprocessable();
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => $payload['password']])->assertOk();
    }

    public function test_queued_password_recovery_cannot_send_after_a_pause_or_epoch_change(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $box = Mailbox::factory()->create(['sending_enabled' => true, 'smtp_host' => 'smtp.example.com']);
        $token = Password::createToken($user);
        $job = new SendPasswordRecovery($user->id, $box->id, $token, 0);
        app(MailSafety::class)->manualPause($user->id);
        Mail::shouldReceive('build')->never();
        $job->handle();
        app(MailSafety::class)->resume($user->id, 'Reviewed sending configuration', 0);
        $job->handle();
        $this->assertDatabaseCount('mail_delivery_attempts', 0);
    }

    public function test_recovery_email_uses_the_configured_mailbox_and_delivery_failures_count(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $box = Mailbox::factory()->create(['sending_enabled' => true, 'smtp_host' => 'smtp.example.com']);
        $token = Password::createToken($user);
        $mailer = app('mail.manager')->mailer('array');
        Mail::shouldReceive('build')->once()->andReturn($mailer);
        (new SendPasswordRecovery($user->id, $box->id, $token, 0))->handle();
        $sent = $mailer->getSymfonyTransport()->messages()->first()->getOriginalMessage();
        $this->assertStringContainsString('/reset-password?token=', $sent->getTextBody());
        $this->assertSame($user->email, $sent->getTo()[0]->getAddress());
        $this->assertSame($box->email, $sent->getFrom()[0]->getAddress());
        $this->assertDatabaseCount('mail_delivery_attempts', 1);
        $attempt = DB::table('mail_delivery_attempts')->first();
        $this->assertNotNull($attempt->sent_at);
        $this->assertNull($attempt->message_id);
        app(MailSafety::class)->recordFailure($attempt->id, 'bounce', 'Recovery bounced');
        $this->assertSame(1, app(MailSafety::class)->status()['recent_failures']);
    }

    public function test_non_admin_cannot_manage_keys_or_remove_suppressions(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $this->actingAs($agent)->getJson('/api/v1/api-keys')->assertForbidden();
        $this->postJson('/api/v1/api-keys', [])->assertForbidden();
        $this->postJson('/api/v1/mail-policy/suppressions', [])->assertForbidden();
        $this->deleteJson('/api/v1/mail-policy/suppressions/1', ['review' => 'Review done'])->assertForbidden();
    }

    public function test_incoming_delivery_and_abuse_reports_match_the_original_message_and_recipient(): void
    {
        $message = $this->outgoing(['requester_email' => 'customer@example.com']);
        app(MailSafety::class)->begin($message);
        $message->update(['delivery' => 'sent']);
        foreach (['delivery-status', 'feedback-report'] as $kind) {
            $fields = $kind === 'delivery-status'
                ? "Reporting-MTA: dns; mx.example.com\r\n\r\nFinal-Recipient: rfc822; customer@example.com\r\nAction: delivered\r\nStatus: 2.0.0\r\n"
                : "Feedback-Type: abuse\r\nUser-Agent: Provider/1.0\r\nVersion: 1\r\nOriginal-Rcpt-To: <customer@example.com>\r\n";
            $mime = "From: Reports <reports@example.com>\r\nTo: support@example.com\r\nDate: Fri, 11 Sep 2026 12:00:00 +0000\r\nMessage-ID: <report-{$kind}@example.com>\r\nSubject: Email report\r\nMIME-Version: 1.0\r\nContent-Type: multipart/report; report-type={$kind}; boundary=\"report-boundary\"\r\n\r\n".
                "--report-boundary\r\nContent-Type: text/plain\r\n\r\nEmail report.\r\n".
                "--report-boundary\r\nContent-Type: message/{$kind}\r\n\r\n{$fields}\r\n".
                "--report-boundary\r\nContent-Type: text/rfc822-headers\r\n\r\nMessage-ID: <{$message->external_id}>\r\nTo: customer@example.com\r\n\r\n--report-boundary--\r\n";
            $mail = \Webklex\PHPIMAP\Message::fromString($mime);
            $this->assertTrue(app(DeliveryReport::class)->consume($message->ticket->mailbox, $mail));
        }
        $this->assertSame('delivered', $message->fresh()->delivery);
        $this->assertDatabaseHas('recipient_suppressions', ['email' => 'customer@example.com', 'reason' => 'complaint']);
        $this->assertDatabaseCount('delivery_events', 2);
        $this->assertSame(0, app(MailSafety::class)->status()['recent_failures']);
    }
}
