<?php

namespace Tests\Feature;

use App\Models\Automation;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\SavedView;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Services\IncomingMail;
use App\Services\WorkflowActions;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class TicketArchivingTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_status_and_source_is_archived_after_sixty_days_without_touching_other_folders(): void
    {
        $this->travelTo(now()->startOfSecond());
        $old = now()->subDays(60);
        $expired = collect();
        foreach (Ticket::STATUSES as $status) {
            foreach (['Email', 'Web', 'Webhook', 'Phone'] as $source) {
                $expired->push(Ticket::factory()->create(['status' => $status, 'source' => $source, 'last_activity_at' => $old]));
            }
        }
        $preserved = collect(['archive', 'spam', 'trash'])->map(fn (string $folder) => Ticket::factory()->create(['folder' => $folder, 'last_activity_at' => $old])->fresh());
        $recent = Ticket::factory()->create(['created_at' => now()->subYear(), 'last_activity_at' => $old->copy()->addSecond()]);

        $this->artisan('tickets:archive-inactive', ['--dry-run' => true])->expectsOutput('20 conversation(s) are due for archiving.')->assertSuccessful();
        $this->assertSame('inbox', $expired->first()->fresh()->folder);
        $this->artisan('tickets:archive-inactive')->expectsOutput('Archived 20 conversation(s) after 60 days without activity.')->assertSuccessful();
        foreach ($expired as $ticket) {
            $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'folder' => 'archive', 'status' => $ticket->status, 'source' => $ticket->source]);
            $this->assertTrue($ticket->fresh()->last_activity_at->equalTo($old));
        }
        foreach ($preserved as $ticket) {
            $this->assertSame($ticket->getAttributes(), $ticket->fresh()->getAttributes());
        }
        $this->assertSame('inbox', $recent->fresh()->folder);
        $this->artisan('tickets:archive-inactive')->expectsOutput('Archived 0 conversation(s) after 60 days without activity.')->assertSuccessful();
        $this->travel(1)->second();
        $this->artisan('tickets:archive-inactive')->assertSuccessful();
        $this->assertSame('archive', $recent->fresh()->folder);
    }

    public function test_active_search_counts_and_saved_views_exclude_expired_tickets_before_the_scheduler_runs(): void
    {
        $this->travelTo(now()->startOfSecond());
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user);
        $group = Team::factory()->create();
        $mailbox = Mailbox::factory()->create(['team_id' => $group->id]);
        $view = SavedView::factory()->create(['filters' => [], 'tag' => 'Orders']);
        foreach (Ticket::STATUSES as $status) {
            foreach ([now(), now()->subDays(60)] as $activity) {
                $ticket = Ticket::factory()->create(['subject' => 'Searchable order', 'status' => $status, 'assignee_id' => $user->id, 'mailbox_id' => $mailbox->id, 'tags' => ['Orders'], 'last_activity_at' => $activity]);
                Message::factory()->create(['ticket_id' => $ticket->id, 'body' => 'BODY-TOKEN']);
            }
        }
        Ticket::factory()->create(['subject' => 'Searchable order', 'mailbox_id' => Mailbox::factory()->create()->id]);
        foreach (['mailbox_id='.$mailbox->id, 'team_id='.$group->id] as $scope) {
            foreach (['all', 'mine', 'unread', 'saved:'.$view->id] as $filter) {
                $this->getJson('/api/v1/tickets?'.$scope.'&view='.urlencode($filter).'&search=BODY-TOKEN')->assertOk()->assertJsonPath('total', 5)
                    ->assertJsonPath('counts.all', 5)->assertJsonPath('counts.mine', 5)->assertJsonPath('counts.unassigned', 0)->assertJsonPath('views.0.count', 5);
            }
            foreach (Ticket::STATUSES as $status) {
                $this->getJson('/api/v1/tickets?'.$scope.'&view='.urlencode($status).'&search=Searchable')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('counts.'.$status, 1);
            }
        }
    }

    public function test_archive_has_a_separate_paginated_search_across_all_dates_and_keeps_account_scope(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $mailbox = Mailbox::factory()->create();
        Ticket::factory()->count(51)->create(['subject' => 'Historical invoice', 'mailbox_id' => $mailbox->id, 'last_activity_at' => now()->subYear()]);
        Ticket::factory()->create(['subject' => 'Historical invoice', 'last_activity_at' => now()->subYear()]);
        $active = Ticket::factory()->create(['subject' => 'Historical invoice', 'mailbox_id' => $mailbox->id]);
        $this->artisan('tickets:archive-inactive')->assertSuccessful();
        $query = '&mailbox_id='.$mailbox->id.'&search=Historical';
        $this->getJson('/api/v1/tickets?view=all'.$query)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('tickets.0.id', $active->id);
        $first = $this->getJson('/api/v1/tickets?view=archive'.$query)->assertOk()->assertJsonPath('total', 51)->assertJsonPath('last_page', 2)->assertJsonCount(50, 'tickets');
        $second = $this->getJson('/api/v1/tickets?view=archive'.$query.'&page=2')->assertOk()->assertJsonCount(1, 'tickets');
        $this->assertNotContains($second->json('tickets.0.id'), array_column($first->json('tickets'), 'id'));
        $oldId = $second->json('tickets.0.id');
        Message::factory()->create(['ticket_id' => $oldId, 'body' => 'ARCHIVED-BODY-ONLY']);
        $this->getJson('/api/v1/tickets?search=ARCHIVED-BODY-ONLY')->assertJsonPath('total', 0);
        $this->getJson('/api/v1/tickets?view=archive&search=ARCHIVED-BODY-ONLY')->assertJsonPath('total', 1)->assertJsonPath('tickets.0.id', $oldId);
    }

    public function test_restoring_an_old_ticket_starts_a_new_active_period_for_manual_and_workflow_moves(): void
    {
        $this->travelTo(now()->startOfSecond());
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $ticket = Ticket::factory()->create(['last_activity_at' => now()->subDays(90)]);
        $this->artisan('tickets:archive-inactive')->assertSuccessful();
        $this->patchJson('/api/v1/tickets/'.$ticket->id, ['folder' => 'inbox'])->assertOk();
        $this->assertTrue($ticket->fresh()->last_activity_at->equalTo(now()));
        $this->getJson('/api/v1/tickets')->assertJsonPath('total', 1);
        $this->travel(59)->days();
        $this->artisan('tickets:archive-inactive')->assertSuccessful();
        $this->assertSame('inbox', $ticket->fresh()->folder);
        $this->travel(1)->day();
        $this->artisan('tickets:archive-inactive')->assertSuccessful();
        $this->assertSame('archive', $ticket->fresh()->folder);
        app(WorkflowActions::class)->apply($ticket->fresh(), [['type' => 'folder', 'value' => 'inbox']], 'Restore macro');
        $this->assertTrue($ticket->fresh()->last_activity_at->equalTo(now()));
        $this->getJson('/api/v1/tickets')->assertJsonPath('total', 1);
    }

    public function test_archiving_preserves_merged_messages_files_and_import_receipts_and_a_new_reply_reopens_the_conversation(): void
    {
        Storage::fake('local');
        $this->travelTo(now()->startOfSecond());
        $mailbox = Mailbox::factory()->create();
        $importer = app(IncomingMail::class);
        $mail = ['external_id' => 'parent@example.com', 'from_email' => 'customer@example.com', 'subject' => 'Order', 'body' => 'Original message', 'attachments' => [['name' => 'invoice.txt', 'content' => 'Invoice']]];
        $parent = $importer->import($mailbox, $mail);
        $child = $importer->import($mailbox, [...$mail, 'external_id' => 'child@example.com']);
        $child->forceFill(['merged_into_id' => $parent->id])->save();
        $files = [$parent->messages()->first()->attachments[0]['path'], $child->messages()->first()->attachments[0]['path']];
        $this->travel(60)->days();
        $this->artisan('tickets:archive-inactive')->expectsOutput('Archived 1 conversation(s) after 60 days without activity.')->assertSuccessful();
        $this->assertSame('archive', $parent->fresh()->folder);
        $this->assertSame($parent->id, $child->fresh()->merged_into_id);
        $this->assertDatabaseCount('messages', 2);
        $this->assertDatabaseCount('mail_import_receipts', 2);
        Storage::disk('local')->assertExists($files);
        $this->assertNull($importer->import($mailbox, $mail));
        $this->assertSame('archive', $parent->fresh()->folder);

        $reply = $importer->import($mailbox, [...$mail, 'external_id' => 'new-reply@example.com', 'references' => ['child@example.com'], 'attachments' => []]);
        $this->assertSame($parent->id, $reply->id);
        $this->assertSame('inbox', $reply->folder);
        $this->assertTrue($reply->last_activity_at->equalTo(now()));
        $this->assertDatabaseCount('messages', 3);
    }

    public function test_archiving_rechecks_activity_after_loading_a_batch_and_processes_every_batch(): void
    {
        $this->travelTo(now()->startOfSecond());
        $updated = Ticket::factory()->create(['last_activity_at' => now()->subDays(90)]);
        Ticket::factory()->count(501)->create(['last_activity_at' => now()->subDays(90)]);
        $interleaved = false;
        DB::listen(function (QueryExecuted $event) use ($updated, &$interleaved): void {
            if (! $interleaved && str_starts_with($event->sql, 'select') && str_contains($event->sql, 'last_activity_at')) {
                $interleaved = true;
                $updated->update(['last_activity_at' => now()]);
            }
        });
        $this->artisan('tickets:archive-inactive')->expectsOutput('Archived 501 conversation(s) after 60 days without activity.')->assertSuccessful();
        $this->assertTrue($interleaved);
        $this->assertSame('inbox', $updated->fresh()->folder);
        $this->assertSame(501, Ticket::where('folder', 'archive')->count());
    }

    public function test_sidebar_refreshes_only_fetch_counts_and_search_requests_can_skip_recalculating_them(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $ticket = Ticket::factory()->create(['subject' => 'Recent order']);
        Message::factory()->create(['ticket_id' => $ticket->id, 'body' => str_repeat('Long message ', 1000)]);
        DB::enableQueryLog();
        $this->getJson('/api/v1/tickets?counts_only=1')->assertOk()->assertJsonPath('counts.all', 1)->assertJsonMissingPath('tickets');
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();
        $this->assertCount(1, $queries->filter(fn (string $sql) => str_contains($sql, 'from "tickets"')));
        $this->assertCount(0, $queries->filter(fn (string $sql) => str_contains($sql, 'from "messages"')));
        $this->getJson('/api/v1/tickets?search=Recent&include_counts=0')->assertOk()->assertJsonPath('total', 1)->assertJsonMissingPath('counts')->assertJsonMissingPath('views')->assertJsonMissingPath('tickets.0.latest_message.body');
    }

    public function test_list_delivery_details_are_loaded_in_one_query_and_large_message_bodies_are_not_returned(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach (range(1, 5) as $index) {
            $ticket = Ticket::factory()->create();
            $attemptId = (string) Str::uuid();
            Message::factory()->create(['ticket_id' => $ticket->id, 'kind' => 'inbound', 'body' => 'Old message']);
            $message = Message::factory()->create(['ticket_id' => $ticket->id, 'kind' => 'outbound', 'delivery' => 'delivered', 'attempt_id' => $attemptId, 'body' => str_repeat('Large reply ', 1000)]);
            DB::table('mail_delivery_attempts')->insert(['id' => $attemptId, 'message_id' => $message->id, 'external_id' => 'delivery-'.$index, 'recipient' => $ticket->requester_email, 'created_at' => now(), 'opened_at' => now(), 'delivered_at' => now()]);
        }
        DB::enableQueryLog();
        $response = $this->getJson('/api/v1/tickets?include_counts=0')->assertOk()->assertJsonCount(5, 'tickets');
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();
        $this->assertCount(1, $queries->filter(fn (string $sql) => str_contains($sql, 'from "mail_delivery_attempts"')));
        foreach ($response->json('tickets') as $ticket) {
            $this->assertSame('delivered', $ticket['latest_message']['delivery']);
            $this->assertNotNull($ticket['latest_message']['opened_at']);
            $this->assertArrayNotHasKey('body', $ticket['latest_message']);
        }
    }

    public function test_archive_and_active_queries_use_the_folder_date_index(): void
    {
        foreach ([Ticket::inInbox(), Ticket::awaitingArchive()] as $query) {
            $plan = DB::select('EXPLAIN QUERY PLAN '.$query->toSql(), $query->getBindings());
            $this->assertStringContainsString('tickets_folder_activity_index', implode(' ', array_column($plan, 'detail')));
        }
    }

    public function test_archiving_runs_each_minute_and_time_rules_only_scan_recent_active_tickets(): void
    {
        $old = Ticket::factory()->create(['last_activity_at' => now()->subDays(61)]);
        $active = Ticket::factory()->create();
        Automation::factory()->create(['trigger' => 'time.elapsed', 'actions' => [['type' => 'priority', 'value' => 'High']]]);
        $this->artisan('helpdesk:automate')->assertSuccessful();
        $this->assertSame('Normal', $old->fresh()->priority);
        $this->assertSame('High', $active->fresh()->priority);
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'tickets:archive-inactive'));
        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);

    }
}
