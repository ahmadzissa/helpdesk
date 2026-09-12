<?php

namespace Tests\Feature;

use App\Models\Mailbox;
use App\Models\Ticket;
use App\Models\User;
use App\Services\IncomingMail;
use App\Services\WorkflowActions;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Webklex\PHPIMAP\ClientManager;

class TrashRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_and_bulk_folder_changes_start_cancel_and_restart_the_trash_clock(): void
    {
        $this->travelTo(now()->startOfSecond());
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $ticket = Ticket::factory()->create(['created_at' => now()->subYear()]);
        $other = Ticket::factory()->create();
        $this->assertNull($ticket->trashed_at);

        $this->patchJson('/api/v1/tickets/'.$ticket->id, ['folder' => 'trash', 'trashed_at' => now()->subMonth()->toIso8601String()])->assertOk();
        $startedAt = now();
        $this->assertTrue($ticket->fresh()->trashed_at->equalTo($startedAt));

        $this->travel(6)->days();
        $this->patchJson('/api/v1/tickets/'.$ticket->id, ['folder' => 'trash', 'unread' => false, 'status' => 'Closed'])->assertOk();
        $this->assertTrue($ticket->fresh()->trashed_at->equalTo($startedAt));
        $this->postJson('/api/v1/tickets/bulk', ['ids' => [$ticket->id, $other->id], 'changes' => ['folder' => 'inbox']])->assertOk();
        $this->assertNull($ticket->fresh()->trashed_at);

        $this->travel(2)->days();
        $this->artisan('tickets:prune-trash')->assertSuccessful();
        $this->assertDatabaseCount('tickets', 2);
        $this->postJson('/api/v1/tickets/bulk', ['ids' => [$ticket->id, $other->id], 'changes' => ['folder' => 'trash']])->assertOk();
        $this->assertTrue($ticket->fresh()->trashed_at->equalTo(now()));
        $this->assertTrue($other->fresh()->trashed_at->equalTo(now()));

        $this->travel(6)->days();
        $this->artisan('tickets:prune-trash')->assertSuccessful();
        $this->assertDatabaseCount('tickets', 2);
        $this->travel(1)->day();
        $this->artisan('tickets:prune-trash')->assertSuccessful();
        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_workflow_folder_actions_use_the_same_retention_clock(): void
    {
        $this->travelTo(now()->startOfSecond());
        $ticket = Ticket::factory()->create();
        $actions = app(WorkflowActions::class);
        $actions->apply($ticket, [['type' => 'folder', 'value' => 'trash']], 'Trash macro');
        $startedAt = now();
        $this->assertTrue($ticket->fresh()->trashed_at->equalTo($startedAt));

        $this->travel(2)->days();
        $actions->apply($ticket, [['type' => 'folder', 'value' => 'trash'], ['type' => 'priority', 'value' => 'High']], 'Repeat rule');
        $this->assertTrue($ticket->fresh()->trashed_at->equalTo($startedAt));
        foreach (['archive', 'spam', 'inbox'] as $folder) {
            $actions->apply($ticket, [['type' => 'folder', 'value' => $folder]], 'Restore rule');
            $this->assertNull($ticket->fresh()->trashed_at);
            $actions->apply($ticket, [['type' => 'folder', 'value' => 'trash']], 'Trash rule');
            $this->assertTrue($ticket->fresh()->trashed_at->equalTo(now()));
            $this->travel(1)->hour();
        }
    }

    public function test_cleanup_requires_seven_full_days_in_trash_and_never_uses_ticket_age_or_status(): void
    {
        $this->travelTo(now()->startOfSecond());
        $startedAt = now();
        $expired = collect(Ticket::STATUSES)->map(fn (string $status) => Ticket::factory()->create(['folder' => 'trash', 'status' => $status]));
        $preserved = collect(['inbox', 'archive', 'spam'])->map(fn (string $folder) => Ticket::factory()->create(['folder' => $folder, 'created_at' => now()->subYear()]));
        $this->travel(1)->second();
        $younger = Ticket::factory()->create(['folder' => 'trash']);

        $this->travelTo($startedAt->copy()->addDays(7));
        $justTrashed = Ticket::factory()->create(['folder' => 'trash', 'created_at' => now()->subYear()]);
        $expired->first()->update(['unread' => false, 'last_activity_at' => now()]);
        $this->artisan('tickets:prune-trash')->assertSuccessful();

        foreach ($expired as $ticket) {
            $this->assertModelMissing($ticket);
        }
        foreach ($preserved->push($younger, $justTrashed) as $ticket) {
            $this->assertModelExists($ticket);
        }
        $this->travel(1)->second();
        $this->artisan('tickets:prune-trash')->assertSuccessful();
        $this->assertModelMissing($younger);
        $this->assertModelExists($justTrashed);
    }

    public function test_new_incoming_replies_do_not_extend_the_trash_deadline(): void
    {
        $this->travelTo(now()->startOfSecond());
        $mailbox = Mailbox::factory()->create();
        $importer = app(IncomingMail::class);
        $mail = ['external_id' => 'original@example.com', 'from_email' => 'customer@example.com', 'subject' => 'Question', 'body' => 'Help'];
        $ticket = $importer->import($mailbox, $mail);
        $ticket->update(['folder' => 'trash']);
        $startedAt = now();

        $this->travel(6)->days();
        $reply = $importer->import($mailbox, [...$mail, 'external_id' => 'reply@example.com', 'references' => [$mail['external_id']]]);
        $this->assertSame($ticket->id, $reply->id);
        $this->assertSame('trash', $reply->folder);
        $this->assertTrue($reply->trashed_at->equalTo($startedAt));
        $this->assertTrue($reply->last_activity_at->equalTo(now()));

        $this->travel(1)->day();
        $this->artisan('tickets:prune-trash')->assertSuccessful();
        $this->assertModelMissing($ticket);
        $this->assertDatabaseCount('mail_import_receipts', 2);
    }

    public function test_expired_conversations_remove_merged_tickets_and_files_but_preserve_import_receipts_and_other_conversations(): void
    {
        Storage::fake('local');
        $this->travelTo(now()->startOfSecond());
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $mailbox = Mailbox::factory()->create();
        $importer = app(IncomingMail::class);
        $parentMail = ['external_id' => 'parent@example.com', 'from_email' => 'customer@example.com', 'subject' => 'Question', 'body' => 'Help', 'attachments' => [['name' => 'receipt.txt', 'content' => 'Receipt']]];
        $childMail = [...$parentMail, 'external_id' => 'child@example.com'];
        $parent = $importer->import($mailbox, $parentMail);
        $child = $importer->import($mailbox, $childMail);
        $this->postJson('/api/v1/tickets/'.$parent->id.'/merge', ['ticket_ids' => [$child->id]])->assertOk();
        $this->putJson('/api/v1/tickets/'.$parent->id.'/draft', ['body' => 'Draft', 'private' => false])->assertOk();
        $this->postJson('/api/v1/tickets/'.$parent->id.'/follow-ups', ['body' => 'Follow up', 'due_at' => now()->addDays(10)->toIso8601String(), 'cancel_on_reply' => false])->assertCreated();
        $image = $this->post('/api/v1/tickets/'.$parent->id.'/inline-images', ['image' => UploadedFile::fake()->image('picture.png')], ['Accept' => 'application/json'])->assertCreated()->json();
        $files = [$parent->messages()->first()->attachments[0]['path'], $child->messages()->first()->attachments[0]['path'], DB::table('inline_images')->where('id', $image['id'])->value('path')];
        $parent->update(['folder' => 'trash']);

        $active = $importer->import($mailbox, [...$parentMail, 'external_id' => 'active@example.com']);
        $activeChild = $importer->import($mailbox, [...$parentMail, 'external_id' => 'active-child@example.com']);
        $activeChild->update(['folder' => 'trash']);
        $this->postJson('/api/v1/tickets/'.$active->id.'/merge', ['ticket_ids' => [$activeChild->id]])->assertOk();
        $this->mock(ClientManager::class)->shouldNotReceive('make');

        $this->travel(7)->days();
        $this->artisan('tickets:prune-trash')->expectsOutput('Permanently deleted 2 ticket(s) after seven days in Trash.')->assertSuccessful();
        $this->assertModelMissing($parent);
        $this->assertModelMissing($child);
        $this->assertModelExists($active);
        $this->assertModelExists($activeChild);
        $this->assertModelExists($mailbox);
        $this->assertDatabaseCount('messages', 2);
        $this->assertDatabaseCount('ticket_drafts', 0);
        $this->assertDatabaseCount('follow_ups', 0);
        $this->assertDatabaseCount('inline_images', 0);
        Storage::disk('local')->assertMissing($files);
        Storage::disk('local')->assertExists([$active->messages()->first()->attachments[0]['path'], $activeChild->messages()->first()->attachments[0]['path']]);
        $this->assertDatabaseCount('mail_import_receipts', 4);
        $this->assertNull($importer->import($mailbox, $parentMail));
        $this->assertNull($importer->import($mailbox, $childMail));
        $this->artisan('tickets:prune-trash')->expectsOutput('Permanently deleted 0 ticket(s) after seven days in Trash.')->assertSuccessful();
    }

    public function test_cleanup_processes_every_batch_without_skipping_deleted_rows(): void
    {
        $this->travelTo(now()->startOfSecond());
        Ticket::factory()->count(201)->create(['folder' => 'trash']);
        $this->travel(7)->days();
        $this->artisan('tickets:prune-trash')->expectsOutput('Permanently deleted 201 ticket(s) after seven days in Trash.')->assertSuccessful();
        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_upgrade_grants_existing_trash_tickets_seven_days_without_changing_activity_dates(): void
    {
        $this->travelTo(now()->startOfSecond());
        $trash = Ticket::factory()->create(['folder' => 'trash']);
        $inbox = Ticket::factory()->create();
        $oldUpdatedAt = $trash->updated_at;
        $this->travel(30)->days();

        $migration = require database_path('migrations/2026_09_11_213633_add_trashed_at_to_tickets_table.php');
        $migration->down();
        $migration->up();

        $this->assertTrue($trash->fresh()->trashed_at->equalTo(now()));
        $this->assertTrue($trash->fresh()->updated_at->equalTo($oldUpdatedAt));
        $this->assertNull($inbox->fresh()->trashed_at);
        $this->artisan('tickets:prune-trash')->assertSuccessful();
        $this->assertModelExists($trash);
        $this->travel(7)->days();
        $this->artisan('tickets:prune-trash')->assertSuccessful();
        $this->assertModelMissing($trash);
        $this->assertModelExists($inbox);
    }

    public function test_trash_cleanup_is_scheduled_hourly_without_overlapping(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'tickets:prune-trash'));
        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
