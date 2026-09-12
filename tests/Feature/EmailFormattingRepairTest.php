<?php

namespace Tests\Feature;

use App\Models\Mailbox;
use App\Models\Message;
use App\Services\ImapInbox;
use App\Services\IncomingMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;
use Webklex\PHPIMAP\Message as ImapMessage;

class EmailFormattingRepairTest extends TestCase
{
    use RefreshDatabase;

    public function test_repair_recovers_existing_message_and_leaves_ticket_cursor_and_receipts_unchanged(): void
    {
        $box = Mailbox::factory()->create(['incoming_enabled' => true]);
        $ticket = app(IncomingMail::class)->import($box, ['external_id' => 'old@example.com', 'from_email' => 'customer@example.com', 'subject' => 'Old email', 'body' => 'Plain text']);
        $ticket->update(['folder' => 'archive', 'status' => 'Closed']);
        $message = $ticket->messages()->first();
        DB::table('message_translations')->insert(['message_id' => $message->id, 'target_language' => 'en', 'source_language' => 'es', 'body' => 'Stale text', 'source_hash' => hash('sha256', $message->body)]);
        $ticketBefore = $ticket->fresh()->getAttributes();
        $mailboxBefore = $box->fresh()->getAttributes();
        $original = ImapMessage::fromString("From: Customer <customer@example.com>\r\nMessage-ID: <old@example.com>\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n<p>Original <b>format</b></p>");
        $inbox = Mockery::mock(ImapInbox::class);
        $inbox->shouldReceive('findMessage')->once()->with(Mockery::on(fn ($mailbox) => $mailbox->id === $box->id), 'old@example.com')->andReturn($original);
        $inbox->shouldNotReceive('receive');
        $this->app->instance(ImapInbox::class, $inbox);
        $this->artisan('mailboxes:repair-formatting', ['--message' => $message->id])->assertSuccessful();
        $this->assertStringContainsString('<b>format</b>', $message->fresh()->email_html);
        $this->assertSame('Plain text', $message->fresh()->body);
        $this->assertSame($ticketBefore, $ticket->fresh()->getAttributes());
        $this->assertSame($mailboxBefore, $box->fresh()->getAttributes());
        $this->assertDatabaseCount('tickets', 1);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('mail_import_receipts', 1);
        $this->assertDatabaseCount('message_translations', 0);
        $this->artisan('mailboxes:repair-formatting', ['--message' => $message->id])->assertSuccessful();
    }

    public function test_repair_requires_an_existing_connected_incoming_message(): void
    {
        $this->artisan('mailboxes:repair-formatting')->assertFailed();
        $this->artisan('mailboxes:repair-formatting', ['--message' => 999])->assertFailed();
        $message = Message::factory()->create(['kind' => 'outbound']);
        $this->artisan('mailboxes:repair-formatting', ['--message' => $message->id])->assertFailed();
    }
}
