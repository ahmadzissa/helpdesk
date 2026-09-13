<?php

namespace Tests\Feature;

use App\Jobs\SendTicketReply;
use App\Models\Automation;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AutomationEngine;
use App\Services\FollowUpRunner;
use App\Services\MailSafety;
use App\Services\WorkflowActions;
use App\Services\WorkflowConditions;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AutomationRuleParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmation_requires_one_requester_message_and_respects_exclusions_and_sending_pause(): void
    {
        Queue::fake([SendTicketReply::class]);
        $admin = User::factory()->create(['role' => 'admin']);
        $mailbox = Mailbox::factory()->create(['sending_enabled' => true]);
        app(MailSafety::class)->manualPause($admin->id);
        $this->actingAs($admin)->postJson('/api/v1/workflows/rules', [
            'name' => 'New ticket confirmation', 'description' => 'Confirm the first customer message.',
            'enabled' => true, 'trigger' => 'ticket.created', 'repeat_mode' => 'once',
            'conditions' => ['all' => [
                ['field' => 'customer_message_count', 'operator' => 'eq', 'value' => '1'],
                ['field' => 'subject', 'operator' => 'not_contains', 'value' => 'New Installation request Plan:'],
                ['field' => 'subject', 'operator' => 'not_contains', 'value' => 'amazon'],
            ]],
            'actions' => [['type' => 'send_message', 'value' => 'Hello {{ticket.requesterName}}, we received #{{ticket_id}}.']],
        ])->assertOk()->assertJsonPath('description', 'Confirm the first customer message.');
        $ticket = Ticket::factory()->create(['requester_name' => 'Sam', 'mailbox_id' => $mailbox->id, 'subject' => 'Need help']);
        Message::factory()->create(['ticket_id' => $ticket->id]);

        app(AutomationEngine::class)->run($ticket, 'ticket.created');
        app(AutomationEngine::class)->run($ticket, 'ticket.created');

        $this->assertSame(1, $ticket->messages()->where('kind', 'outbound')->count());
        $this->assertDatabaseHas('messages', ['ticket_id' => $ticket->id, 'body' => 'Hello Sam, we received #'.$ticket->id.'.', 'delivery' => 'held']);
        foreach (['Amazon problem', 'New Installation request Plan: premium', 'Two messages', 'Spam'] as $subject) {
            $excluded = Ticket::factory()->create(['mailbox_id' => $mailbox->id, 'subject' => $subject, 'folder' => $subject === 'Spam' ? 'spam' : 'inbox']);
            Message::factory()->count($subject === 'Two messages' ? 2 : 1)->create(['ticket_id' => $excluded->id]);
            app(AutomationEngine::class)->run($excluded, 'ticket.created');
            $this->assertSame(0, $excluded->messages()->where('kind', 'outbound')->count());
        }
        Queue::assertNotPushed(SendTicketReply::class);
    }

    #[TestWith(['Need AMAZON help', 'amazon', false, true])]
    #[TestWith(['Need amazonian help', 'amazon', false, false])]
    #[TestWith(["Shopify\n  Shop question", 'Shopify Shop', false, true])]
    #[TestWith(['SHOPIFY Shop', 'Shopify Shop', true, false])]
    #[TestWith(['(Shopify Shop)!', 'Shopify Shop', true, true])]
    #[TestWith(['éamazon', 'amazon', false, false])]
    public function test_phrase_matching_respects_words_spaces_and_case(string $body, string $phrase, bool $caseSensitive, bool $matches): void
    {
        $ticket = Ticket::factory()->create();
        Message::factory()->create(['ticket_id' => $ticket->id, 'body' => $body]);
        $condition = ['field' => 'body', 'operator' => 'contains_phrase', 'value' => $phrase, 'case_sensitive' => $caseSensitive];

        $this->assertSame($matches, app(WorkflowConditions::class)->matches($ticket, ['all' => [$condition]]));
        $condition['operator'] = 'not_contains_phrase';
        $this->assertSame(! $matches, app(WorkflowConditions::class)->matches($ticket, ['all' => [$condition]]));
    }

    public function test_status_timer_restarts_only_on_status_change_and_scheduler_runs_once(): void
    {
        $this->freezeTime();
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = Ticket::factory()->create();
        $this->actingAs($admin)->postJson('/api/v1/workflows/rules', [
            'name' => 'After solved', 'enabled' => true, 'trigger' => 'time.elapsed', 'repeat_mode' => 'once',
            'conditions' => ['all' => [
                ['field' => 'status', 'operator' => 'eq', 'value' => 'Solved'],
                ['field' => 'since_status_minutes', 'operator' => 'gte', 'value' => '60'],
            ]],
            'actions' => [['type' => 'note', 'value' => 'One hour since solved']],
        ])->assertOk();
        $this->patchJson('/api/v1/tickets/'.$ticket->id, ['status' => 'Solved'])->assertOk();
        $this->travel(30)->minutes();
        $this->patchJson('/api/v1/tickets/'.$ticket->id, ['priority' => 'High'])->assertOk();
        $this->artisan('helpdesk:automate')->assertSuccessful();
        $this->assertSame(0, $ticket->messages()->count());
        $this->travel(30)->minutes();
        $this->artisan('helpdesk:automate')->assertSuccessful();
        $this->artisan('helpdesk:automate')->assertSuccessful();
        $this->assertSame(1, $ticket->messages()->count());

        $ticket->refresh()->update(['status' => 'Open']);
        $ticket->update(['status' => 'Solved']);
        $this->assertFalse(app(WorkflowConditions::class)->matches($ticket, ['all' => [['field' => 'since_status_minutes', 'operator' => 'gte', 'value' => '60']]]));
    }

    public function test_pending_closure_waits_for_latest_selected_event_and_ignores_unsent_replies(): void
    {
        $this->freezeTime();
        $ticket = Ticket::factory()->create(['status' => 'Pending']);
        $rule = Automation::factory()->create(['trigger' => 'time.elapsed', 'conditions' => ['all' => [
            ['field' => 'status', 'operator' => 'eq', 'value' => 'Pending'],
            ['field' => 'since_events_minutes', 'operator' => 'gte', 'value' => '2880', 'events' => ['status_changed', 'message_sent', 'follow_up_sent']],
        ]], 'actions' => [['type' => 'status', 'value' => 'Closed']]]);
        $this->travel(1)->days();
        DB::transaction(fn () => app(WorkflowActions::class)->apply($ticket, [['type' => 'send_follow_up', 'value' => 'Checking in']], 'Follow-up'));
        $followUpMessage = $ticket->messages()->where('kind', 'outbound')->first();
        $followUpMessage->forceFill(['delivery' => 'sent', 'sent_at' => now()])->save();
        $this->travel(1)->days();
        app(AutomationEngine::class)->run($ticket, 'time.elapsed');
        $this->assertSame('Pending', $ticket->fresh()->status);
        $this->travel(1)->days();
        Message::factory()->create(['ticket_id' => $ticket->id, 'kind' => 'outbound', 'delivery' => 'held']);

        app(AutomationEngine::class)->run($ticket, 'time.elapsed');

        $this->assertSame('Closed', $ticket->fresh()->status);
        $this->assertDatabaseHas('automation_runs', ['automation_id' => $rule->id, 'ticket_id' => $ticket->id]);
    }

    public function test_follow_up_counts_distinguish_sent_attempted_cancelled_and_rule_scope(): void
    {
        $this->freezeTime();
        $ticket = Ticket::factory()->create();
        $rule = Automation::factory()->create();
        $otherRule = Automation::factory()->create();
        DB::transaction(function () use ($ticket, $rule, $otherRule): void {
            app(WorkflowActions::class)->apply($ticket, [['type' => 'send_follow_up', 'value' => 'First']], 'First', automationId: $rule->id);
            app(WorkflowActions::class)->apply($ticket, [['type' => 'follow_up', 'value' => 10, 'body' => 'Later']], 'Later', automationId: $otherRule->id);
        });
        $sentCondition = ['field' => 'follow_up_count', 'operator' => 'eq', 'value' => '0', 'scope' => 'anyone'];
        $conditions = app(WorkflowConditions::class);
        $this->assertTrue($conditions->matches($ticket, ['all' => [$sentCondition]], $rule->id));
        $this->assertTrue($conditions->matches($ticket, ['all' => [['field' => 'follow_up_created_count', 'operator' => 'eq', 'value' => '2']]], $rule->id));
        $ticket->messages()->first()->forceFill(['delivery' => 'sent', 'sent_at' => now()])->save();
        $sentCondition['value'] = '1';
        $sentCondition['scope'] = 'rule';
        $this->assertTrue($conditions->matches($ticket, ['all' => [$sentCondition]], $rule->id));
        $this->assertFalse($conditions->matches($ticket, ['all' => [$sentCondition]], $otherRule->id));
        DB::table('follow_ups')->where('automation_id', $otherRule->id)->update(['state' => 'cancelled']);
        $this->assertTrue($conditions->matches($ticket, ['all' => [['field' => 'follow_up_created_count', 'operator' => 'eq', 'value' => '1']]]));
    }

    public function test_scheduled_follow_up_keeps_rule_attribution_and_is_processed_once(): void
    {
        $this->freezeTime();
        $ticket = Ticket::factory()->create();
        $rule = Automation::factory()->create(['actions' => [['type' => 'follow_up', 'value' => 10, 'body' => 'Hi {{name}}']]]);
        app(AutomationEngine::class)->run($ticket, 'ticket.created');
        $this->travel(10)->minutes();

        app(FollowUpRunner::class)->run();
        app(FollowUpRunner::class)->run();

        $this->assertSame(1, $ticket->messages()->where('kind', 'outbound')->count());
        $this->assertDatabaseHas('follow_ups', ['automation_id' => $rule->id, 'state' => 'processed']);
    }

    public function test_four_hour_rule_requires_one_previous_follow_up_from_that_same_rule(): void
    {
        $this->freezeTime();
        $ticket = Ticket::factory()->create(['status' => 'Open']);
        $rule = Automation::factory()->create(['trigger' => 'time.elapsed', 'repeat_mode' => 'interval', 'interval_minutes' => 60, 'max_runs' => 2, 'conditions' => ['all' => [
            ['field' => 'status', 'operator' => 'eq', 'value' => 'Open'],
            ['field' => 'since_events_minutes', 'operator' => 'gte', 'value' => '240', 'events' => ['activity']],
            ['field' => 'follow_up_count', 'operator' => 'eq', 'value' => '1', 'scope' => 'rule'],
        ]], 'actions' => [['type' => 'send_follow_up', 'value' => 'Sorry for the delay']]]);
        $this->travel(4)->hours();
        app(AutomationEngine::class)->run($ticket, 'time.elapsed');
        $this->assertSame(0, $ticket->messages()->count());
        $restrictedConditions = $rule->conditions;
        $rule->update(['conditions' => []]);
        app(AutomationEngine::class)->run($ticket, 'time.elapsed');
        $rule->update(['conditions' => $restrictedConditions]);
        $ticket->messages()->first()->forceFill(['delivery' => 'sent', 'sent_at' => now()])->save();
        $this->travel(239)->minutes();
        app(AutomationEngine::class)->run($ticket, 'time.elapsed');
        $this->assertSame(1, $ticket->messages()->count());
        $this->travel(1)->minutes();

        app(AutomationEngine::class)->run($ticket, 'time.elapsed');
        app(AutomationEngine::class)->run($ticket, 'time.elapsed');

        $this->assertSame(2, $ticket->messages()->count());
        $this->assertDatabaseHas('messages', ['ticket_id' => $ticket->id, 'body' => 'Sorry for the delay']);
    }

    public function test_delivery_records_sent_time_and_increments_follow_up_count_only_after_success(): void
    {
        $this->travelTo(Carbon::parse('2026-09-13 12:00:00'));
        Queue::fake([SendTicketReply::class]);
        $mailbox = Mailbox::factory()->create(['sending_enabled' => true, 'smtp_host' => 'smtp.example.com']);
        $ticket = Ticket::factory()->create(['mailbox_id' => $mailbox->id]);
        $rule = Automation::factory()->create(['actions' => [['type' => 'send_follow_up', 'value' => 'Checking in']]]);
        app(AutomationEngine::class)->run($ticket, 'ticket.created');
        $message = $ticket->messages()->firstOrFail();
        Queue::assertPushed(SendTicketReply::class, fn (SendTicketReply $job): bool => $job->message->id === $message->id);
        $this->assertNull($message->sent_at);
        $condition = ['all' => [['field' => 'follow_up_count', 'operator' => 'eq', 'value' => '1', 'scope' => 'rule']]];
        $this->assertFalse(app(WorkflowConditions::class)->matches($ticket, $condition, $rule->id));
        $mailer = app('mail.manager')->mailer('array');
        Mail::shouldReceive('build')->once()->andReturn($mailer);
        $this->travel(5)->minutes();

        (new SendTicketReply($message))->handle();

        $this->assertSame('2026-09-13 12:05:00', $message->fresh()->sent_at->toDateTimeString());
        $this->assertTrue(app(WorkflowConditions::class)->matches($ticket, $condition, $rule->id));
        $this->assertCount(1, $mailer->getSymfonyTransport()->messages());
        $this->assertSame('2026-09-13 12:05:00', $ticket->fresh()->workflow_activity_at->toDateTimeString());
    }

    public function test_missing_event_history_does_not_match_and_different_timers_can_select_the_same_event(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = Ticket::factory()->create();
        $ticket->forceFill(['status_changed_at' => null])->save();
        $conditions = ['all' => [
            ['field' => 'since_events_minutes', 'operator' => 'gte', 'value' => '60', 'events' => ['status_changed']],
            ['field' => 'since_events_minutes', 'operator' => 'lte', 'value' => '120', 'events' => ['status_changed']],
        ]];
        $this->assertFalse(app(WorkflowConditions::class)->matches($ticket, $conditions));

        $this->actingAs($admin)->postJson('/api/v1/workflows/rules', [
            'name' => 'Time window', 'enabled' => false, 'trigger' => 'time.elapsed', 'conditions' => $conditions,
            'actions' => [['type' => 'send_message', 'value' => 'Hello']],
        ])->assertOk()->assertJsonPath('conditions', $conditions);

        $this->assertDatabaseHas('automations', ['name' => 'Time window', 'enabled' => false]);
    }

    public function test_activity_timer_includes_notes_and_ticket_edits_but_not_scheduler_checks_or_read_markers(): void
    {
        $this->freezeTime();
        $ticket = Ticket::factory()->create();
        $condition = ['all' => [['field' => 'since_events_minutes', 'operator' => 'gte', 'value' => '240', 'events' => ['activity']]]];
        $this->travel(4)->hours();
        app(AutomationEngine::class)->run($ticket, 'time.elapsed');
        $ticket->refresh()->update(['unread' => false]);
        $this->assertTrue(app(WorkflowConditions::class)->matches($ticket->fresh(), $condition));
        $ticket->update(['priority' => 'High']);
        $this->assertFalse(app(WorkflowConditions::class)->matches($ticket->fresh(), $condition));
        $this->travel(4)->hours();
        Message::factory()->create(['ticket_id' => $ticket->id, 'kind' => 'note']);
        $this->assertFalse(app(WorkflowConditions::class)->matches($ticket->fresh(), $condition));
    }

    #[TestWith([['field' => 'since_events_minutes', 'operator' => 'gte', 'value' => '60'], 'Choose at least one event for the timer.'])]
    #[TestWith([['field' => 'customer_message_count', 'operator' => 'eq', 'value' => '-1'], 'Counts and timers require a non-negative number.'])]
    #[TestWith([['field' => 'follow_up_count', 'operator' => 'eq', 'value' => '1.5'], 'Event counts must be whole numbers.'])]
    #[TestWith([['field' => 'status', 'operator' => 'contains_phrase', 'value' => 'Open'], 'Phrase matching is available for the subject and customer message.'])]
    public function test_invalid_condition_returns_422_without_saving(array $condition, string $message): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson('/api/v1/workflows/rules', [
            'name' => 'Invalid', 'enabled' => false, 'trigger' => 'time.elapsed',
            'conditions' => ['all' => [$condition]], 'actions' => [['type' => 'send_message', 'value' => 'Hello']],
        ])->assertUnprocessable()->assertJsonPath('message', $message);

        $this->assertDatabaseCount('automations', 0);
    }
}
