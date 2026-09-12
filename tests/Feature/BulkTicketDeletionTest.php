<?php

namespace Tests\Feature;

use App\Jobs\SendTicketReply;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Services\IncomingMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;
use Webklex\PHPIMAP\ClientManager;

class BulkTicketDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function mail(string $id): array
    {
        return ['external_id' => $id.'@example.com', 'from_email' => 'customer@example.com', 'subject' => 'Please help', 'body' => 'My question',
            'attachments' => [['name' => 'receipt.txt', 'content' => 'Receipt contents']]];
    }

    public function test_bulk_delete_removes_selected_conversations_and_files_from_any_folder_without_reimporting_them(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $mailbox = Mailbox::factory()->create();
        $importer = app(IncomingMail::class);
        $parent = $importer->import($mailbox, $this->mail('parent'));
        $child = $importer->import($mailbox, $this->mail('child'));
        $child->forceFill(['merged_into_id' => $parent->id])->save();
        $untouched = $importer->import($mailbox, $this->mail('untouched'));
        $selectedIds = [$parent->id];
        foreach (['archive', 'spam', 'trash'] as $folder) {
            $ticket = Ticket::factory()->create(['mailbox_id' => $mailbox->id, 'folder' => $folder]);
            $selectedIds[] = $ticket->id;
        }
        $files = [$parent->messages()->first()->attachments[0]['path'], $child->messages()->first()->attachments[0]['path']];
        $this->putJson('/api/v1/tickets/'.$parent->id.'/draft', ['body' => 'Draft reply', 'private' => false])->assertOk();
        $this->postJson('/api/v1/tickets/'.$parent->id.'/follow-ups', ['body' => 'Follow up', 'due_at' => now()->addDay()->toIso8601String(), 'cancel_on_reply' => false])->assertCreated();
        $image = $this->post('/api/v1/tickets/'.$parent->id.'/inline-images', ['image' => UploadedFile::fake()->image('picture.png')], ['Accept' => 'application/json'])->assertCreated()->json();
        $files[] = DB::table('inline_images')->where('id', $image['id'])->value('path');
        $this->mock(ClientManager::class)->shouldNotReceive('make');

        $this->deleteJson('/api/v1/tickets/bulk', ['ids' => $selectedIds, 'confirmed' => true])->assertOk()->assertJsonPath('deleted', 5);

        $this->assertDatabaseCount('tickets', 1);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseHas('tickets', ['id' => $untouched->id, 'mailbox_id' => $mailbox->id]);
        $this->assertDatabaseHas('mailboxes', ['id' => $mailbox->id]);
        $this->assertDatabaseCount('ticket_drafts', 0);
        $this->assertDatabaseCount('follow_ups', 0);
        $this->assertDatabaseCount('inline_images', 0);
        Storage::disk('local')->assertMissing($files);
        Storage::disk('local')->assertExists($untouched->messages()->first()->attachments[0]['path']);
        $this->assertDatabaseCount('mail_import_receipts', 3);
        $this->assertNull($importer->import($mailbox, $this->mail('parent')));
        $this->assertNull($importer->import($mailbox, $this->mail('child')));
        $this->getJson('/api/v1/tickets')->assertJsonPath('counts.all', 1);
    }

    public function test_bulk_delete_requires_an_administrator_confirmation_and_valid_unique_ids_before_deleting_anything(): void
    {
        $tickets = Ticket::factory()->count(2)->create();
        $payload = ['ids' => $tickets->modelKeys(), 'confirmed' => true];
        $this->deleteJson('/api/v1/tickets/bulk', $payload)->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'agent']));
        $this->deleteJson('/api/v1/tickets/bulk', $payload)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->deleteJson('/api/v1/tickets/bulk', ['ids' => $tickets->modelKeys()])->assertUnprocessable();
        $this->deleteJson('/api/v1/tickets/bulk', [...$payload, 'confirmed' => false])->assertUnprocessable();
        foreach ([[], [$tickets[0]->id, 999999], [$tickets[0]->id, $tickets[0]->id]] as $ids) {
            $this->deleteJson('/api/v1/tickets/bulk', ['ids' => $ids, 'confirmed' => true])->assertUnprocessable();
        }
        $this->assertDatabaseCount('tickets', 2);
    }

    public function test_bulk_delete_rejects_direct_deletion_of_a_merged_child_without_deleting_other_selected_tickets(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $tickets = Ticket::factory()->count(3)->create();
        $tickets[2]->forceFill(['merged_into_id' => $tickets[1]->id])->save();

        $this->deleteJson('/api/v1/tickets/bulk', ['ids' => [$tickets[0]->id, $tickets[2]->id], 'confirmed' => true])->assertConflict();

        $this->assertDatabaseCount('tickets', 3);
    }

    public function test_bulk_delete_supports_selecting_more_than_one_page_of_tickets(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $selected = Ticket::factory()->count(105)->create();
        $untouched = Ticket::factory()->create();

        $this->deleteJson('/api/v1/tickets/bulk', ['ids' => $selected->modelKeys(), 'confirmed' => true])->assertOk()->assertJsonPath('deleted', 105);

        $this->assertDatabaseCount('tickets', 1);
        $this->assertDatabaseHas('tickets', ['id' => $untouched->id]);
    }

    public function test_queued_replies_for_deleted_tickets_are_discarded_without_sending_email(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $ticket = Ticket::factory()->create();
        $message = Message::factory()->create(['ticket_id' => $ticket->id, 'kind' => 'outbound', 'delivery' => 'queued']);
        $queue = Queue::connection('database');
        $queue->push(new SendTicketReply($message));
        Mail::shouldReceive('build')->never();
        $this->deleteJson('/api/v1/tickets/bulk', ['ids' => [$ticket->id], 'confirmed' => true])->assertOk();

        $job = $queue->pop();
        $this->assertNotNull($job);
        $job->fire();

        $this->assertTrue($job->isDeleted());
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseCount('mail_delivery_attempts', 0);
    }

    public function test_database_rollback_keeps_ticket_files_until_the_deletion_is_committed(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $ticket = app(IncomingMail::class)->import(Mailbox::factory()->create(), $this->mail('rollback'));
        $path = $ticket->messages()->first()->attachments[0]['path'];
        try {
            DB::transaction(function () use ($ticket): void {
                $this->deleteJson('/api/v1/tickets/bulk', ['ids' => [$ticket->id], 'confirmed' => true])->assertOk();
                throw new RuntimeException('Rollback deletion');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Rollback deletion', $exception->getMessage());
        }

        $this->assertDatabaseHas('tickets', ['id' => $ticket->id]);
        Storage::disk('local')->assertExists($path);
    }
}
