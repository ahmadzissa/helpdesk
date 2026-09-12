<?php

namespace Tests\Feature;

use App\Models\Mailbox;
use App\Models\Message;
use App\Models\SavedView;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketCountingTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_status_and_saved_view_counts_only_include_inbox_tickets_in_the_selected_account_or_group(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user);
        $team = Team::factory()->create();
        $mailbox = Mailbox::factory()->create(['team_id' => $team->id]);
        $view = SavedView::factory()->create(['filters' => [], 'tag' => null]);
        foreach (['inbox', 'archive', 'spam', 'trash'] as $folder) {
            foreach (Ticket::STATUSES as $status) {
                Ticket::factory()->create(['mailbox_id' => $mailbox->id, 'folder' => $folder, 'status' => $status, 'assignee_id' => $user->id]);
            }
        }
        Ticket::factory()->create(['mailbox_id' => Mailbox::factory()->create()->id]);
        foreach (['mailbox_id='.$mailbox->id, 'team_id='.$team->id] as $scope) {
            $response = $this->getJson('/api/v1/tickets?'.$scope)->assertOk()->assertJsonPath('total', 5)
                ->assertJsonPath('counts.all', 5)->assertJsonPath('counts.mine', 5)->assertJsonPath('counts.unassigned', 0)->assertJsonPath('views.0.count', 5);
            foreach (Ticket::STATUSES as $status) {
                $response->assertJsonPath('counts.'.$status, 1);
                $this->getJson('/api/v1/tickets?'.$scope.'&view='.urlencode($status))->assertOk()->assertJsonPath('total', 1);
            }
            $this->getJson('/api/v1/tickets?'.$scope.'&view=saved:'.$view->id)->assertOk()->assertJsonPath('total', 5);
            foreach (['archive', 'spam', 'trash'] as $folder) {
                $this->getJson('/api/v1/tickets?'.$scope.'&view='.$folder)->assertOk()->assertJsonPath('total', 5)->assertJsonPath('counts.all', 5)->assertJsonPath('counts.Open', 1);
            }
        }
    }

    public function test_moving_tickets_out_of_the_inbox_removes_them_from_all_status_and_saved_view_counts_until_restored(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        SavedView::factory()->create(['filters' => [], 'tag' => null]);
        $ticket = Ticket::factory()->create(['status' => 'Pending']);
        foreach (['archive', 'spam', 'trash'] as $folder) {
            $this->patchJson('/api/v1/tickets/'.$ticket->id, ['folder' => $folder])->assertOk();
            $this->getJson('/api/v1/tickets')->assertOk()->assertJsonPath('total', 0)->assertJsonPath('counts.all', 0)
                ->assertJsonPath('counts.Pending', 0)->assertJsonPath('counts.unassigned', 0)->assertJsonPath('views.0.count', 0);
        }
        $this->patchJson('/api/v1/tickets/'.$ticket->id, ['folder' => 'inbox'])->assertOk();
        $this->getJson('/api/v1/tickets')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('counts.all', 1)->assertJsonPath('counts.Pending', 1)->assertJsonPath('views.0.count', 1);
    }

    public function test_reports_exclude_separate_folders_and_merged_tickets_from_every_metric(): void
    {
        $this->freezeTime();
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user);
        $open = Ticket::factory()->create(['assignee_id' => $user->id, 'created_at' => now()->subMinutes(20)]);
        $solved = Ticket::factory()->create(['assignee_id' => $user->id, 'status' => 'Solved', 'resolved_at' => now(), 'created_at' => now()->subMinutes(20)]);
        Message::factory()->create(['ticket_id' => $solved->id, 'kind' => 'outbound', 'delivery' => 'sent', 'created_at' => now()->subMinutes(10)]);
        foreach (['archive', 'spam', 'trash', 'inbox'] as $folder) {
            $excluded = Ticket::factory()->create(['assignee_id' => $user->id, 'folder' => $folder, 'status' => 'Closed', 'resolved_at' => now(), 'created_at' => now()->subDays(2)]);
            if ($folder === 'inbox') {
                $excluded->forceFill(['merged_into_id' => $open->id])->save();
            }
            Message::factory()->create(['ticket_id' => $excluded->id, 'kind' => 'outbound', 'delivery' => 'failed']);
        }

        $response = $this->getJson('/api/v1/reports?days=7')->assertOk()->assertJsonPath('total', 2)
            ->assertJsonPath('resolved', 1)->assertJsonPath('resolution_rate', 50)->assertJsonPath('response_minutes', 10)
            ->assertJsonPath('statuses', ['Open' => 1, 'Solved' => 1])->assertJsonPath('agents.0.assigned', 2)->assertJsonPath('agents.0.resolved', 1)
            ->assertJsonPath('delivery', [['delivery' => 'sent', 'total' => 1]]);
        $this->assertSame(2, array_sum(array_column($response->json('trend'), 'created')));
        $this->assertSame(1, array_sum(array_column($response->json('trend'), 'resolved')));
        $this->getJson('/api/v1/tickets')->assertJsonPath('counts.all', 2)->assertJsonPath('counts.Closed', 0);
    }

    public function test_held_reply_count_and_review_list_exclude_tickets_outside_the_inbox(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $ticket = Ticket::factory()->create();
        $message = Message::factory()->create(['ticket_id' => $ticket->id, 'kind' => 'outbound', 'delivery' => 'held']);
        foreach (['archive', 'spam', 'trash', 'inbox'] as $folder) {
            $excluded = Ticket::factory()->create(['folder' => $folder]);
            if ($folder === 'inbox') {
                $excluded->forceFill(['merged_into_id' => $ticket->id])->save();
            }
            Message::factory()->create(['ticket_id' => $excluded->id, 'kind' => 'outbound', 'delivery' => 'held']);
        }

        $this->getJson('/api/v1/sending-safety?review=1')->assertOk()->assertJsonPath('held_count', 1)->assertJsonCount(1, 'held')->assertJsonPath('held.0.id', $message->id);
    }
}
