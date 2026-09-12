<?php

namespace Tests\Feature;

use App\Jobs\SendTicketReply;
use App\Models\Automation;
use App\Models\CannedReply;
use App\Models\Mailbox;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AutomationEngine;
use App\Services\FollowUpRunner;
use App\Services\IncomingMail;
use App\Services\MailSafety;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkflowFeaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_and_any_conditions_apply_multiple_actions_in_order_and_repeat_for_events(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = Ticket::factory()->create(['subject' => 'Order delivery problem', 'tags' => ['old']]);
        $reply = CannedReply::factory()->create(['body' => 'Hi {{name}} on #{{ticket_id}}']);
        $response = $this->actingAs($admin)->postJson('/api/v1/workflows/rules', [
            'name' => 'Order routing', 'enabled' => true, 'trigger' => 'ticket.updated', 'repeat_mode' => 'event', 'max_runs' => 2,
            'conditions' => ['all' => [['field' => 'subject', 'operator' => 'contains', 'value' => 'ORDER']], 'any' => [['field' => 'priority', 'operator' => 'eq', 'value' => 'Normal'], ['field' => 'priority', 'operator' => 'eq', 'value' => 'High']]],
            'actions' => [['type' => 'priority', 'value' => 'High'], ['type' => 'remove_tag', 'value' => 'old'], ['type' => 'add_tag', 'value' => 'Order'], ['type' => 'note', 'value' => 'Internal context'], ['type' => 'reply_id', 'value' => $reply->id]],
        ])->assertOk();
        $engine = app(AutomationEngine::class);
        $engine->run($ticket, 'ticket.updated');
        $this->assertSame('High', $ticket->fresh()->priority);
        $this->assertSame(['Order'], $ticket->fresh()->tags);
        $this->assertSame(2, $ticket->messages()->count());
        $engine->run($ticket, 'ticket.updated');
        $engine->run($ticket, 'ticket.updated');
        $this->assertSame(4, $ticket->messages()->count());
        $this->assertDatabaseCount('automation_runs', 2);
        $this->assertSame('saved', $ticket->messages()->where('kind', 'outbound')->first()->delivery);
        $other = Ticket::factory()->create(['subject' => 'Unrelated', 'priority' => 'Normal']);
        $engine->run($other, 'ticket.updated');
        $this->assertSame('Normal', $other->fresh()->priority);
    }

    public function test_time_rules_obey_interval_and_maximum_and_do_not_repeat_within_a_scheduler_tick(): void
    {
        $this->freezeTime();
        $ticket = Ticket::factory()->create(['created_at' => now()->subHours(2), 'last_activity_at' => now()->subHours(2)]);
        Automation::factory()->create(['trigger' => 'time.elapsed', 'repeat_mode' => 'interval', 'interval_minutes' => 30, 'max_runs' => 2,
            'conditions' => ['all' => [['field' => 'age_minutes', 'operator' => 'gte', 'value' => '60']], 'any' => []],
            'actions' => [['type' => 'note', 'value' => 'Check this ticket']],
        ]);
        $this->artisan('helpdesk:automate')->assertSuccessful();
        $this->artisan('helpdesk:automate')->assertSuccessful();
        $this->assertSame(1, $ticket->messages()->count());
        $this->travel(31)->minutes();
        $this->artisan('helpdesk:automate')->assertSuccessful();
        $this->travel(31)->minutes();
        $this->artisan('helpdesk:automate')->assertSuccessful();
        $this->assertSame(2, $ticket->messages()->count());
    }

    public function test_missing_customer_or_reply_timestamp_does_not_match_elapsed_time(): void
    {
        $ticket = Ticket::factory()->create();
        Automation::factory()->create(['trigger' => 'time.elapsed', 'conditions' => ['all' => [['field' => 'since_reply_minutes', 'operator' => 'gte', 'value' => '0']]], 'actions' => [['type' => 'priority', 'value' => 'Urgent']]]);
        app(AutomationEngine::class)->run($ticket, 'time.elapsed');
        $this->assertSame('Normal', $ticket->fresh()->priority);
    }

    public function test_macro_is_idempotent_and_automated_email_remains_held_while_paused(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $box = Mailbox::factory()->create(['sending_enabled' => true]);
        $ticket = Ticket::factory()->create(['mailbox_id' => $box->id]);
        $reply = CannedReply::factory()->create();
        $macro = $this->actingAs($admin)->postJson('/api/v1/workflows/macros', ['name' => 'Resolve', 'enabled' => true, 'actions' => [['type' => 'reply_id', 'value' => $reply->id], ['type' => 'status', 'value' => 'Solved']]])->assertOk()->json('id');
        app(MailSafety::class)->manualPause($admin->id);
        $payload = ['request_id' => (string) Str::uuid()];
        $url = '/api/v1/tickets/'.$ticket->id.'/macros/'.$macro;
        $this->postJson($url, $payload)->assertOk();
        $this->postJson($url, $payload)->assertOk();
        $this->assertSame(1, $ticket->messages()->count());
        $this->assertSame('held', $ticket->messages()->first()->delivery);
        $this->assertSame('Solved', $ticket->fresh()->status);
        Queue::assertNotPushed(SendTicketReply::class);
    }

    public function test_follow_up_cancels_on_customer_reply_and_due_messages_are_held_once(): void
    {
        Queue::fake();
        $this->freezeTime();
        $admin = User::factory()->create(['role' => 'admin']);
        $box = Mailbox::factory()->create(['sending_enabled' => true]);
        $ticket = Ticket::factory()->create(['mailbox_id' => $box->id]);
        $url = '/api/v1/tickets/'.$ticket->id.'/follow-ups';
        $this->actingAs($admin)->postJson($url, ['body' => 'Checking in', 'due_at' => now()->addMinutes(10)->toIso8601String(), 'cancel_on_reply' => true])->assertCreated();
        app(IncomingMail::class)->import($box, ['external_id' => 'customer-reply@example.com', 'from_email' => $ticket->requester_email, 'subject' => 'Re: [#'.$ticket->id.']', 'body' => 'I replied']);
        $this->assertDatabaseHas('follow_ups', ['ticket_id' => $ticket->id, 'state' => 'cancelled', 'result' => 'Customer replied']);
        $this->postJson($url, ['body' => 'Second check', 'due_at' => now()->addMinutes(10)->toIso8601String(), 'cancel_on_reply' => false])->assertCreated();
        app(MailSafety::class)->manualPause($admin->id);
        $this->travel(11)->minutes();
        app(FollowUpRunner::class)->run();
        app(FollowUpRunner::class)->run();
        $this->assertSame(1, $ticket->messages()->where('kind', 'outbound')->count());
        $this->assertDatabaseHas('follow_ups', ['ticket_id' => $ticket->id, 'state' => 'processed', 'result' => 'held']);
        app(MailSafety::class)->resume($admin->id, 'Reviewed all bounce issues', 0);
        app(FollowUpRunner::class)->run();
        $this->assertSame('held', $ticket->messages()->where('kind', 'outbound')->first()->delivery);
        Queue::assertNotPushed(SendTicketReply::class);
    }

    public function test_agents_cannot_manage_rules_and_invalid_actions_or_time_repeats_are_rejected(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $this->actingAs($agent)->postJson('/api/v1/workflows/macros', [])->assertForbidden();
        $agent->update(['role' => 'admin']);
        $payload = ['name' => 'Invalid', 'enabled' => true, 'trigger' => 'time.elapsed', 'repeat_mode' => 'event', 'conditions' => ['all' => []], 'actions' => [['type' => 'status', 'value' => 'Open']]];
        $this->postJson('/api/v1/workflows/rules', $payload)->assertUnprocessable();
        $payload['repeat_mode'] = 'once';
        $payload['actions'][0]['value'] = 'Unknown';
        $this->postJson('/api/v1/workflows/rules', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('automations', 0);
    }
}
