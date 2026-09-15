<?php

namespace Tests\Feature;

use App\Jobs\SendTicketReply;
use App\Jobs\SyncMailbox;
use App\Models\Automation;
use App\Models\CannedReply;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Services\IncomingMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IncomingMailTest extends TestCase
{
    use RefreshDatabase;

    private function mail(array $changes = []): array
    {
        return array_replace(['external_id' => 'message-1@example.com', 'from_email' => 'olivia@example.com', 'from_name' => 'Olivia', 'subject' => 'Please help with an order', 'body' => 'Here is my question.', 'references' => [], 'automated' => false, 'attachments' => []], $changes);
    }

    public function test_incoming_email_creates_ticket_and_repeated_import_is_idempotent(): void
    {
        $box = Mailbox::factory()->create();
        $importer = app(IncomingMail::class);
        $ticket = $importer->import($box, $this->mail());
        $this->assertSame('olivia@example.com', $ticket->requester_email);
        $this->assertSame($box->id, $ticket->mailbox_id);
        $this->assertTrue($ticket->unread);
        $this->assertNull($importer->import($box, $this->mail()));
        $this->assertDatabaseCount('tickets', 1);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_reply_threads_only_to_the_same_mailbox_and_requester(): void
    {
        $box = Mailbox::factory()->create();
        $importer = app(IncomingMail::class);
        $ticket = $importer->import($box, $this->mail());
        $ticket->update(['status' => 'Solved', 'folder' => 'archive', 'resolved_at' => now()]);
        $reply = $importer->import($box, $this->mail(['external_id' => 'reply@example.com', 'references' => ['message-1@example.com'], 'body' => 'A follow-up']));
        $this->assertSame($ticket->id, $reply->id);
        $this->assertSame('Open', $reply->status);
        $this->assertSame('inbox', $reply->folder);
        $this->assertNull($reply->resolved_at);
        $stranger = $importer->import($box, $this->mail(['external_id' => 'stranger@example.com', 'from_email' => 'stranger@example.com', 'references' => ['message-1@example.com'], 'subject' => 'Re: request [#'.$ticket->id.']']));
        $this->assertNotSame($ticket->id, $stranger->id);
        $otherBox = Mailbox::factory()->create();
        $other = $importer->import($otherBox, $this->mail(['external_id' => 'other@example.com', 'references' => ['message-1@example.com'], 'subject' => 'Re: request [#'.$ticket->id.']']));
        $this->assertNotSame($ticket->id, $other->id);
    }

    public function test_self_sent_bot_request_sends_one_automatic_confirmation_to_customer_reply_to(): void
    {
        Queue::fake();
        $box = Mailbox::factory()->create(['email' => 'support@example.com', 'sending_enabled' => true, 'smtp_host' => 'smtp.example.com']);
        $reply = CannedReply::factory()->create(['body' => 'We received your request, {{name}}.']);
        Automation::factory()->create(['actions' => ['reply_id' => $reply->id]]);
        $importer = app(IncomingMail::class);
        $mail = $this->mail(['from_email' => ' SUPPORT@EXAMPLE.COM ', 'from_name' => 'Support Bot',
            'reply_to_email' => ' Olivia@Example.com ', 'reply_to_name' => 'Olivia Customer']);

        $ticket = $importer->import($box, $mail);

        $this->assertSame('olivia@example.com', $ticket->requester_email);
        $this->assertSame('Olivia Customer', $ticket->requester_name);
        $this->assertSame('olivia@example.com', $ticket->messages()->first()->author_email);
        $this->assertNull($importer->import($box, $mail));
        $this->assertDatabaseCount('automation_runs', 1);
        $confirmation = $ticket->messages()->where('kind', 'outbound')->sole();
        $this->assertSame('We received your request, Olivia Customer.', $confirmation->body);
        Queue::assertPushed(SendTicketReply::class, 1);
        Queue::assertPushed(SendTicketReply::class, fn (SendTicketReply $job): bool => $job->message->id === $confirmation->id);

        $mailer = app('mail.manager')->mailer('array');
        Mail::shouldReceive('build')->once()->andReturn($mailer);
        (new SendTicketReply($confirmation))->handle();
        $sent = $mailer->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
        $this->assertSame('olivia@example.com', $sent->getTo()[0]->getAddress());
        $this->assertSame('support@example.com', $sent->getReplyTo()[0]->getAddress());
        $this->assertSame('auto-replied', $sent->getHeaders()->get('Auto-Submitted')->getBodyAsString());
        $this->assertNull($importer->import($box, $this->mail(['external_id' => $confirmation->fresh()->external_id,
            'from_email' => $box->email, 'reply_to_email' => $box->email, 'automated' => true])));

        $followUp = $importer->import($box, $this->mail(['external_id' => 'follow-up@example.com', 'references' => ['message-1@example.com']]));
        $this->assertSame($ticket->id, $followUp->id);
        $this->assertSame(3, $ticket->messages()->count());
        $this->assertDatabaseCount('automation_runs', 1);
        Queue::assertPushed(SendTicketReply::class, 1);
    }

    public function test_explicitly_automated_self_sent_mail_still_suppresses_confirmation(): void
    {
        Queue::fake();
        $box = Mailbox::factory()->create(['sending_enabled' => true]);
        $reply = CannedReply::factory()->create();
        Automation::factory()->create(['actions' => ['reply_id' => $reply->id]]);

        $ticket = app(IncomingMail::class)->import($box, $this->mail(['from_email' => $box->email,
            'reply_to_email' => 'olivia@example.com', 'automated' => true]));

        $this->assertSame('olivia@example.com', $ticket->requester_email);
        $this->assertSame(1, $ticket->messages()->count());
        $this->assertDatabaseCount('automation_runs', 0);
        Queue::assertNothingPushed();
    }

    /** @return array<string, array{string}> */
    public static function unsafeSelfReplyAddresses(): array
    {
        return ['missing' => [''], 'invalid' => ['not-an-email'], 'same mailbox' => [' SUPPORT@EXAMPLE.COM '],
            'another support mailbox' => ['OTHER@EXAMPLE.COM']];
    }

    #[DataProvider('unsafeSelfReplyAddresses')]
    public function test_self_sent_mail_without_a_customer_reply_address_is_skipped(string $replyTo): void
    {
        $box = Mailbox::factory()->create(['email' => 'support@example.com']);
        Mailbox::factory()->create(['email' => 'other@example.com']);

        $this->assertNull(app(IncomingMail::class)->import($box, $this->mail(['from_email' => $box->email, 'reply_to_email' => $replyTo])));
        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('mail_import_receipts', 0);
    }

    public function test_customer_mail_keeps_its_sender_when_reply_to_differs(): void
    {
        $box = Mailbox::factory()->create();
        $ticket = app(IncomingMail::class)->import($box, $this->mail(['reply_to_email' => 'someone-else@example.com']));

        $this->assertSame('olivia@example.com', $ticket->requester_email);
    }

    public function test_imported_messages_are_not_reimported_months_later_after_archiving_or_deletion(): void
    {
        $mailbox = Mailbox::factory()->create();
        $importer = app(IncomingMail::class);
        $tickets = [];
        foreach (['archive', 'spam', 'trash'] as $folder) {
            $mail = $this->mail(['external_id' => $folder.'@example.com']);
            $ticket = $importer->import($mailbox, $mail);
            $ticket->update(['folder' => $folder, 'unread' => false]);
            $tickets[] = [$ticket, $mail];
        }
        $this->travel(3)->months();
        foreach ($tickets as [$ticket, $mail]) {
            $this->assertNull($importer->import($mailbox, $mail));
            $this->assertSame($ticket->folder, $ticket->fresh()->folder);
            $this->assertFalse($ticket->fresh()->unread);
            $ticket->delete();
            $this->assertNull($importer->import($mailbox, $mail));
        }
        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('mail_import_receipts', 3);
    }

    public function test_incoming_replies_do_not_release_spam_or_trash(): void
    {
        $box = Mailbox::factory()->create();
        $importer = app(IncomingMail::class);
        $ticket = $importer->import($box, $this->mail());
        $ticket->update(['folder' => 'spam']);
        $reply = $importer->import($box, $this->mail(['external_id' => 'spam-reply@example.com', 'subject' => 'Re: request [#'.$ticket->id.']']));
        $this->assertSame($ticket->id, $reply->id);
        $this->assertSame('spam', $reply->folder);
    }

    public function test_automated_inbound_messages_do_not_start_acknowledgment_loops(): void
    {
        Queue::fake();
        $box = Mailbox::factory()->create();
        $reply = CannedReply::factory()->create();
        Automation::factory()->create(['actions' => ['reply_id' => $reply->id]]);
        $ticket = app(IncomingMail::class)->import($box, $this->mail(['automated' => true]));
        $this->assertSame(1, $ticket->messages()->count());
        $this->assertDatabaseCount('automation_runs', 0);
        $this->assertNull(app(IncomingMail::class)->import($box, $this->mail(['from_email' => $box->email])));
        Queue::assertNothingPushed();
    }

    public function test_imported_attachments_use_generated_paths_and_authenticated_downloads(): void
    {
        Storage::fake('local');
        $box = Mailbox::factory()->create();
        $ticket = app(IncomingMail::class)->import($box, $this->mail(['attachments' => [['name' => '../../details.txt', 'content' => 'hello']]]));
        $file = $ticket->messages()->first()->attachments[0];
        $this->assertSame('details.txt', $file['name']);
        $this->assertStringStartsWith('ticket-attachments/', $file['path']);
        $this->assertStringNotContainsString('..', $file['path']);
        Storage::disk('local')->assertExists($file['path']);
    }

    public function test_only_admin_can_queue_enabled_mailbox_sync_and_command_uses_enabled_accounts(): void
    {
        Queue::fake();
        $enabled = Mailbox::factory()->create(['incoming_enabled' => true]);
        $disabled = Mailbox::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'agent']));
        $this->postJson('/api/v1/mailboxes/'.$enabled->id.'/sync')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/v1/mailboxes/'.$disabled->id.'/sync')->assertUnprocessable();
        $this->postJson('/api/v1/mailboxes/'.$enabled->id.'/sync')->assertAccepted();
        Queue::assertPushed(SyncMailbox::class, fn ($job) => $job->mailboxId === $enabled->id);
        Queue::assertNotPushed(SyncMailbox::class, fn ($job) => $job->mailboxId === $disabled->id);
        $this->artisan('mailboxes:sync')->assertSuccessful();
    }

    public function test_message_formatting_is_sanitized_and_inbound_html_is_plain_text(): void
    {
        $outbound = Message::renderBody('**Hello** <script>alert(1)</script> [x](javascript:alert%281%29)', 'outbound');
        $this->assertStringContainsString('<strong>Hello</strong>', $outbound);
        $this->assertStringNotContainsString('<script', $outbound);
        $this->assertStringNotContainsString('href="javascript:', $outbound);
        $inbound = Message::renderBody('<img src=x onerror=alert(1)>', 'inbound');
        $this->assertStringNotContainsString('<img', $inbound);
        $this->assertStringContainsString('&lt;img', $inbound);
    }

    public function test_ticket_api_does_not_disclose_mailbox_configuration_to_agents(): void
    {
        $box = Mailbox::factory()->create(['smtp_host' => 'private.example.com', 'smtp_username' => 'account', 'smtp_password' => 'secret']);
        $ticket = Ticket::factory()->create(['mailbox_id' => $box->id]);
        $this->actingAs(User::factory()->create(['role' => 'agent']));
        $this->patchJson('/api/v1/tickets/'.$ticket->id, ['priority' => 'High'])->assertOk()->assertJsonMissingPath('data.mailbox.smtp_host')->assertJsonMissingPath('data.mailbox.smtp_username')->assertJsonMissingPath('data.mailbox.smtp_password');
    }
}
