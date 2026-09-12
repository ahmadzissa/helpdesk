<?php

namespace Tests\Feature;

use App\Jobs\SyncMailbox;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AutomationEngine;
use App\Services\ImapInbox;
use App\Services\IncomingMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class MailboxRemovalTest extends TestCase
{
    use RefreshDatabase;

    private function mail(): array
    {
        return ['external_id' => 'customer-message@example.com', 'from_email' => 'customer@example.com', 'subject' => 'Support needed', 'body' => 'Please help.'];
    }

    public function test_removing_an_account_preserves_tickets_revokes_its_keys_and_cancels_pending_intake(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $mailbox = Mailbox::factory()->create(['incoming_enabled' => true]);
        $ticket = app(IncomingMail::class)->import($mailbox, [...$this->mail(), 'attachments' => [['name' => 'receipt.txt', 'content' => 'My receipt']]]);
        $message = $ticket->messages()->first();
        $key = $this->postJson('/api/v1/api-keys', ['name' => 'App', 'mailbox_id' => $mailbox->id, 'scopes' => ['tickets:create']])->assertCreated()->json();
        $inbox = $this->mock(ImapInbox::class);
        $inbox->shouldNotReceive('receive');
        $pending = new SyncMailbox($mailbox->id);

        $this->deleteJson('/api/v1/manage/mailboxes/'.$mailbox->id)->assertOk();
        $pending->handle(app(IncomingMail::class), $inbox);

        $this->assertDatabaseMissing('mailboxes', ['id' => $mailbox->id]);
        $this->assertNull($ticket->fresh()->mailbox_id);
        $this->assertNull($message->fresh()->mailbox_id);
        $this->assertSame('Please help.', $message->fresh()->body);
        Storage::disk('local')->assertExists($message->attachments[0]['path']);
        $this->assertDatabaseCount('mail_import_receipts', 1);
        $storedKey = DB::table('api_keys')->where('id', $key['id'])->first();
        $this->assertNull($storedKey->mailbox_id);
        $this->assertNotNull($storedKey->revoked_at);
        $this->withToken($key['token'])->postJson('/api/v1/external/tickets', ['subject' => 'Another request', 'requester_email' => 'customer@example.com', 'body' => 'Hello'])->assertUnauthorized();
    }

    public function test_opt_in_deletes_only_this_accounts_tickets_and_files_across_all_folders_and_merged_conversations(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user);
        $mailbox = Mailbox::factory()->create(['incoming_enabled' => true]);
        $otherMailbox = Mailbox::factory()->create();
        $mail = [...$this->mail(), 'attachments' => [['name' => 'receipt.txt', 'content' => 'My receipt']]];
        $importer = app(IncomingMail::class);
        $ticket = $importer->import($mailbox, $mail);
        $otherTicket = $importer->import($otherMailbox, $mail);
        $unassigned = Ticket::factory()->create(['mailbox_id' => null]);
        $message = $ticket->messages()->firstOrFail();
        $otherMessage = $otherTicket->messages()->firstOrFail();
        foreach (['archive', 'spam', 'trash'] as $folder) {
            Ticket::factory()->create(['mailbox_id' => $mailbox->id, 'folder' => $folder]);
        }
        $merged = Ticket::factory()->create(['mailbox_id' => $mailbox->id, 'requester_email' => $ticket->requester_email]);
        $merged->forceFill(['merged_into_id' => $ticket->id])->save();
        Message::factory()->create(['ticket_id' => $merged->id, 'mailbox_id' => $mailbox->id, 'kind' => 'note']);
        $this->putJson('/api/v1/tickets/'.$ticket->id.'/draft', ['body' => 'Saved draft', 'private' => false])->assertOk();
        $followUp = $this->postJson('/api/v1/tickets/'.$ticket->id.'/follow-ups', ['body' => 'Follow up', 'due_at' => now()->addDay()->toIso8601String(), 'cancel_on_reply' => false])->assertCreated()->json();
        $image = $this->post('/api/v1/tickets/'.$ticket->id.'/inline-images', ['image' => UploadedFile::fake()->image('picture.png')], ['Accept' => 'application/json'])->assertCreated()->json();
        $imagePath = DB::table('inline_images')->where('id', $image['id'])->value('path');
        DB::table('message_translations')->insert(['message_id' => $message->id, 'target_language' => 'fr', 'source_hash' => hash('sha256', $message->body), 'body' => 'Aidez-moi.']);
        DB::table('ticket_translations')->insert(['ticket_id' => $ticket->id, 'target_language' => 'fr', 'source_hash' => hash('sha256', $ticket->subject), 'subject' => 'Assistance']);
        $this->mock(ImapInbox::class)->shouldNotReceive('receive');

        $this->deleteJson('/api/v1/manage/mailboxes/'.$mailbox->id, ['delete_tickets' => true])->assertOk()->assertJsonPath('deleted_tickets', 5);

        $this->assertDatabaseMissing('mailboxes', ['id' => $mailbox->id]);
        $this->assertDatabaseMissing('tickets', ['mailbox_id' => $mailbox->id]);
        $this->assertDatabaseMissing('tickets', ['id' => $ticket->id]);
        $this->assertDatabaseMissing('tickets', ['id' => $merged->id]);
        $this->assertDatabaseMissing('messages', ['id' => $message->id]);
        $this->assertDatabaseMissing('ticket_drafts', ['ticket_id' => $ticket->id]);
        $this->assertDatabaseMissing('follow_ups', ['id' => $followUp['id']]);
        $this->assertDatabaseMissing('inline_images', ['id' => $image['id']]);
        $this->assertDatabaseMissing('message_translations', ['message_id' => $message->id]);
        $this->assertDatabaseMissing('ticket_translations', ['ticket_id' => $ticket->id]);
        $this->assertDatabaseCount('tickets', 2);
        $this->assertDatabaseHas('tickets', ['id' => $otherTicket->id, 'mailbox_id' => $otherMailbox->id]);
        $this->assertDatabaseHas('tickets', ['id' => $unassigned->id, 'mailbox_id' => null]);
        Storage::disk('local')->assertMissing([$message->attachments[0]['path'], $imagePath]);
        Storage::disk('local')->assertExists($otherMessage->attachments[0]['path']);
        $this->assertDatabaseCount('mail_import_receipts', 2);
        $replacement = Mailbox::factory()->create(['email' => $mailbox->email]);
        $this->assertNull($importer->import($replacement, $this->mail()));
        $this->assertDatabaseCount('tickets', 2);
        $this->getJson('/api/v1/tickets/'.$ticket->id)->assertNotFound();
    }

    public function test_explicitly_leaving_the_delete_option_off_keeps_tickets(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $mailbox = Mailbox::factory()->create();
        $ticket = Ticket::factory()->create(['mailbox_id' => $mailbox->id]);

        $this->deleteJson('/api/v1/manage/mailboxes/'.$mailbox->id, ['delete_tickets' => false])->assertOk()->assertJsonPath('deleted_tickets', 0);

        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'mailbox_id' => null]);
    }

    public function test_invalid_delete_options_are_rejected_before_any_account_or_ticket_is_removed(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $mailbox = Mailbox::factory()->create();
        $ticket = Ticket::factory()->create(['mailbox_id' => $mailbox->id]);
        foreach (['false', 'all', [], null] as $option) {
            $this->deleteJson('/api/v1/manage/mailboxes/'.$mailbox->id, ['delete_tickets' => $option])->assertUnprocessable()->assertJsonValidationErrors('delete_tickets');
        }

        $this->assertDatabaseHas('mailboxes', ['id' => $mailbox->id]);
        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'mailbox_id' => $mailbox->id]);
    }

    public function test_trashing_a_ticket_is_local_and_repeated_import_does_not_restore_it(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $mailbox = Mailbox::factory()->create();
        $importer = app(IncomingMail::class);
        $ticket = $importer->import($mailbox, $this->mail());
        $this->mock(ImapInbox::class)->shouldNotReceive('receive');

        $this->patchJson('/api/v1/tickets/'.$ticket->id, ['folder' => 'trash'])->assertOk();
        $this->assertNull($importer->import($mailbox, $this->mail()));
        $this->assertSame('trash', $ticket->fresh()->folder);
        $this->assertDatabaseCount('tickets', 1);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_removing_and_readding_the_same_email_cannot_reimport_deleted_tickets(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $mailbox = Mailbox::factory()->create(['email' => 'support@example.com']);
        $importer = app(IncomingMail::class);
        $ticket = $importer->import($mailbox, $this->mail());
        $ticket->delete();
        $this->deleteJson('/api/v1/manage/mailboxes/'.$mailbox->id)->assertOk();
        $this->travel(1)->hour();
        $replacement = Mailbox::factory()->create(['email' => 'support@example.com']);

        $this->assertTrue($replacement->import_started_at->greaterThan($mailbox->import_started_at));
        $this->assertNull($importer->import($replacement, $this->mail()));
        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('mail_import_receipts', 1);
    }

    public function test_a_failed_import_rolls_back_its_receipt_so_the_next_attempt_can_succeed(): void
    {
        $mailbox = Mailbox::factory()->create();
        $automations = Mockery::mock(AutomationEngine::class);
        $automations->shouldReceive('run')->once()->andThrow(new RuntimeException('Temporary failure'));
        try {
            (new IncomingMail($automations))->import($mailbox, $this->mail());
            $this->fail('The first import should fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Temporary failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('mail_import_receipts', 0);
        $this->assertDatabaseCount('tickets', 0);
        $this->assertNotNull(app(IncomingMail::class)->import($mailbox, $this->mail()));
        $this->assertDatabaseCount('tickets', 1);
        $this->assertDatabaseCount('mail_import_receipts', 1);
    }

    public function test_upgrade_preserves_account_start_time_and_records_previously_imported_emails(): void
    {
        $migration = require database_path('migrations/2026_09_11_205114_add_incremental_mail_import_tracking.php');
        $migration->down();
        $createdAt = now()->subDays(3)->startOfSecond();
        $mailboxId = DB::table('mailboxes')->insertGetId(['name' => 'Support', 'email' => 'support@example.com', 'created_at' => $createdAt, 'updated_at' => $createdAt]);
        $ticket = Ticket::factory()->create(['mailbox_id' => $mailboxId]);
        $ticket->messages()->create(['mailbox_id' => $mailboxId, 'external_id' => $this->mail()['external_id'], 'kind' => 'inbound', 'author_email' => 'customer@example.com', 'body' => 'Previously imported']);
        $migration->up();

        $mailbox = Mailbox::findOrFail($mailboxId);
        $this->assertTrue($mailbox->import_started_at->equalTo($createdAt));
        $ticket->delete();
        $this->assertNull(app(IncomingMail::class)->import($mailbox, $this->mail()));
        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('mail_import_receipts', 1);
    }

    public function test_agents_cannot_remove_email_accounts(): void
    {
        $mailbox = Mailbox::factory()->create();
        $ticket = Ticket::factory()->create(['mailbox_id' => $mailbox->id]);
        $this->actingAs(User::factory()->create(['role' => 'agent']));
        $this->deleteJson('/api/v1/manage/mailboxes/'.$mailbox->id, ['delete_tickets' => true])->assertForbidden();

        $this->assertDatabaseHas('mailboxes', ['id' => $mailbox->id]);
        $this->assertDatabaseHas('tickets', ['id' => $ticket->id]);
    }
}
