<?php

namespace Tests\Feature;

use App\Jobs\SyncMailbox;
use App\Models\Automation;
use App\Models\CannedReply;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Services\IncomingMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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
