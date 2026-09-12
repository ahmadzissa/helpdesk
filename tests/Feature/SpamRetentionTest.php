<?php

namespace Tests\Feature;

use App\Models\Mailbox;
use App\Models\Ticket;
use App\Models\User;
use App\Services\IncomingMail;
use App\Services\WorkflowActions;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Webklex\PHPIMAP\ClientManager;

class SpamRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_and_bulk_folder_changes_start_cancel_and_restart_the_spam_clock(): void
    {
        $this->travelTo(now()->startOfSecond());
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $ticket = Ticket::factory()->create(['created_at' => now()->subYear()]);
        $other = Ticket::factory()->create();
        $this->assertNull($ticket->spammed_at);
        $this->patchJson('/api/v1/tickets/'.$ticket->id, ['folder' => 'spam', 'spammed_at' => now()->subYear()->toIso8601String()])->assertOk();
        $startedAt = now();
        $this->assertTrue($ticket->fresh()->spammed_at->equalTo($startedAt));

        $this->travel(29)->days();
        $this->patchJson('/api/v1/tickets/'.$ticket->id, ['folder' => 'spam', 'unread' => false, 'status' => 'Closed'])->assertOk();
        $this->assertTrue($ticket->fresh()->spammed_at->equalTo($startedAt));
        $this->postJson('/api/v1/tickets/bulk', ['ids' => [$ticket->id, $other->id], 'changes' => ['folder' => 'inbox']])->assertOk();
        $this->assertNull($ticket->fresh()->spammed_at);

        $this->travel(2)->days();
        $this->artisan('tickets:prune-spam')->assertSuccessful();
        $this->assertDatabaseCount('tickets', 2);
        $this->postJson('/api/v1/tickets/bulk', ['ids' => [$ticket->id, $other->id], 'changes' => ['folder' => 'spam']])->assertOk();
        $this->assertTrue($ticket->fresh()->spammed_at->equalTo(now()));
        $this->assertTrue($other->fresh()->spammed_at->equalTo(now()));
        $this->travel(29)->days();
        $this->artisan('tickets:prune-spam')->assertSuccessful();
        $this->assertDatabaseCount('tickets', 2);
        $this->travel(1)->day();
        $this->artisan('tickets:prune-spam')->assertSuccessful();
        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_workflow_actions_reset_the_clock_when_switching_between_spam_trash_and_other_folders(): void
    {
        $this->travelTo(now()->startOfSecond());
        $ticket = Ticket::factory()->create(['folder' => 'spam']);
        $startedAt = now();
        $actions = app(WorkflowActions::class);
        $this->travel(10)->days();
        $actions->apply($ticket, [['type' => 'folder', 'value' => 'spam'], ['type' => 'priority', 'value' => 'High']], 'Repeat rule');
        $this->assertTrue($ticket->fresh()->spammed_at->equalTo($startedAt));
        foreach (['trash', 'archive', 'inbox'] as $folder) {
            $actions->apply($ticket, [['type' => 'folder', 'value' => $folder]], 'Move rule');
            $this->assertNull($ticket->fresh()->spammed_at);
            if ($folder === 'trash') {
                $this->assertTrue($ticket->fresh()->trashed_at->equalTo(now()));
            }
            $this->travel(1)->day();
            $actions->apply($ticket, [['type' => 'folder', 'value' => 'spam']], 'Spam rule');
            $this->assertTrue($ticket->fresh()->spammed_at->equalTo(now()));
            $this->assertNull($ticket->fresh()->trashed_at);
        }
    }

    public function test_cleanup_requires_thirty_full_days_in_spam_regardless_of_ticket_age_activity_or_status(): void
    {
        $this->travelTo(now()->startOfSecond());
        $startedAt = now();
        $expired = collect(Ticket::STATUSES)->map(fn (string $status) => Ticket::factory()->create(['folder' => 'spam', 'status' => $status]));
        $preserved = collect(['inbox', 'archive', 'trash'])->map(fn (string $folder) => Ticket::factory()->create(['folder' => $folder, 'created_at' => now()->subYear()]));
        $this->travel(1)->second();
        $younger = Ticket::factory()->create(['folder' => 'spam']);

        $this->travelTo($startedAt->copy()->addDays(30));
        $justSpammed = Ticket::factory()->create(['folder' => 'spam', 'created_at' => now()->subYear()]);
        $expired->first()->update(['unread' => false, 'last_activity_at' => now()]);
        $this->artisan('tickets:prune-spam')->assertSuccessful();
        foreach ($expired as $ticket) {
            $this->assertModelMissing($ticket);
        }
        foreach ($preserved->push($younger, $justSpammed) as $ticket) {
            $this->assertModelExists($ticket);
        }
        $this->travel(1)->second();
        $this->artisan('tickets:prune-spam')->assertSuccessful();
        $this->assertModelMissing($younger);
        $this->assertModelExists($justSpammed);
    }

    public function test_sender_policy_starts_the_clock_and_new_replies_do_not_extend_it(): void
    {
        $this->travelTo(now()->startOfSecond());
        DB::table('sender_rules')->insert(['kind' => 'email', 'value' => 'customer@example.com', 'action' => 'blocked']);
        $mailbox = Mailbox::factory()->create();
        $importer = app(IncomingMail::class);
        $mail = ['external_id' => 'original@example.com', 'from_email' => 'customer@example.com', 'subject' => 'Question', 'body' => 'Help'];
        $ticket = $importer->import($mailbox, $mail);
        $startedAt = now();
        $this->assertSame('spam', $ticket->folder);
        $this->assertTrue($ticket->spammed_at->equalTo($startedAt));

        $this->travel(29)->days();
        $replyMail = [...$mail, 'external_id' => 'reply@example.com', 'references' => [$mail['external_id']]];
        $reply = $importer->import($mailbox, $replyMail);
        $this->assertSame($ticket->id, $reply->id);
        $this->assertTrue($reply->spammed_at->equalTo($startedAt));
        $this->assertTrue($reply->last_activity_at->equalTo(now()));
        $this->travel(1)->day();
        $this->artisan('tickets:prune-spam')->assertSuccessful();
        $this->assertModelMissing($ticket);
        $this->assertDatabaseCount('mail_import_receipts', 2);
        $this->assertNull($importer->import($mailbox, $mail));
        $this->assertNull($importer->import($mailbox, $replyMail));
    }

    public function test_cleanup_removes_expired_spam_conversations_and_files_while_preserving_merged_children_of_active_tickets(): void
    {
        Storage::fake('local');
        $this->travelTo(now()->startOfSecond());
        $mailbox = Mailbox::factory()->create();
        $importer = app(IncomingMail::class);
        $mail = ['external_id' => 'parent@example.com', 'from_email' => 'customer@example.com', 'subject' => 'Question', 'body' => 'Help', 'attachments' => [['name' => 'receipt.txt', 'content' => 'Receipt']]];
        $parent = $importer->import($mailbox, $mail);
        $child = $importer->import($mailbox, [...$mail, 'external_id' => 'child@example.com']);
        $child->forceFill(['merged_into_id' => $parent->id])->save();
        $parent->update(['folder' => 'spam']);
        $active = $importer->import($mailbox, [...$mail, 'external_id' => 'active@example.com']);
        $activeChild = $importer->import($mailbox, [...$mail, 'external_id' => 'active-child@example.com']);
        $activeChild->forceFill(['folder' => 'spam', 'merged_into_id' => $active->id])->save();
        $files = [$parent->messages()->first()->attachments[0]['path'], $child->messages()->first()->attachments[0]['path']];
        $this->mock(ClientManager::class)->shouldNotReceive('make');

        $this->travel(30)->days();
        $this->artisan('tickets:prune-spam')->expectsOutput('Permanently deleted 2 ticket(s) after 30 days in Spam.')->assertSuccessful();
        $this->assertModelMissing($parent);
        $this->assertModelMissing($child);
        $this->assertModelExists($active);
        $this->assertModelExists($activeChild);
        $this->assertModelExists($mailbox);
        $this->assertDatabaseCount('messages', 2);
        $this->assertDatabaseCount('mail_import_receipts', 4);
        Storage::disk('local')->assertMissing($files);
        Storage::disk('local')->assertExists([$active->messages()->first()->attachments[0]['path'], $activeChild->messages()->first()->attachments[0]['path']]);
        $this->assertNull($importer->import($mailbox, $mail));
        $this->artisan('tickets:prune-spam')->expectsOutput('Permanently deleted 0 ticket(s) after 30 days in Spam.')->assertSuccessful();
    }

    public function test_upgrade_grants_existing_spam_tickets_thirty_days_and_preserves_the_trash_deadline(): void
    {
        $this->travelTo(now()->startOfSecond());
        $spam = Ticket::factory()->create(['folder' => 'spam']);
        $trash = Ticket::factory()->create(['folder' => 'trash']);
        $inbox = Ticket::factory()->create();
        $oldUpdatedAt = $spam->updated_at;
        $trashedAt = $trash->trashed_at;
        $this->travel(40)->days();
        $migration = require database_path('migrations/2026_09_11_214413_add_spammed_at_to_tickets_table.php');
        $migration->down();
        $migration->up();

        $this->assertTrue($spam->fresh()->spammed_at->equalTo(now()));
        $this->assertTrue($spam->fresh()->updated_at->equalTo($oldUpdatedAt));
        $this->assertTrue($trash->fresh()->trashed_at->equalTo($trashedAt));
        $this->assertNull($trash->fresh()->spammed_at);
        $this->assertNull($inbox->fresh()->spammed_at);
        $this->artisan('tickets:prune-spam')->assertSuccessful();
        $this->assertModelExists($spam);
        $this->travel(30)->days();
        $this->artisan('tickets:prune-spam')->assertSuccessful();
        $this->assertModelMissing($spam);
        $this->assertModelExists($trash);
        $this->assertModelExists($inbox);
    }

    public function test_spam_cleanup_is_scheduled_hourly_without_overlapping(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'tickets:prune-spam'));
        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
