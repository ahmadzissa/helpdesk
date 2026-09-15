<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Automation;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AutomationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketNumberDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_activity_uses_descriptions_without_ticket_numbers_on_both_screens(): void
    {
        $ticket = Ticket::factory()->create();
        $descriptions = [
            'Created ticket #31' => 'Created ticket',
            'Updated priority on #31' => 'Updated priority on this ticket',
            'Automation “Rule #52” ran on #31' => 'Automation “Rule #52” ran on this ticket',
            'Added private note to #31' => 'Added private note to this ticket',
            'Replied to #31' => 'Replied to this ticket',
            'Received email for #31 · 2 attachment(s) exceeded the import limits' => 'Received email for this ticket · 2 attachment(s) exceeded the import limits',
            'Bounce received for #31' => 'Bounce received for this ticket',
            'Outgoing delivery failed for #31' => 'Outgoing delivery failed for this ticket',
            'Timed follow-up processed for #31 (sent).' => 'Timed follow-up processed for this ticket (sent).',
            'Applied macro “Rule #52” to #31' => 'Applied macro “Rule #52” to this ticket',
            'Merged #31 into this ticket. Earlier messages remain in #31.' => 'Merged another ticket into this conversation. Earlier messages remain in the original ticket.',
            'Merged into #31. Future customer replies go to the main ticket.' => 'Merged into the main ticket. Future customer replies go to the main ticket.',
            'Scheduled follow-up #52 for #31' => 'Scheduled follow-up for this ticket',
            'Cancelled follow-up #52' => 'Cancelled follow-up',
            'Prepared browser translation for reply #52.' => 'Prepared browser translation for reply.',
            'Customer asked about order #31' => 'Customer asked about order #31',
        ];
        $expected = [];
        foreach ($descriptions as $stored => $displayed) {
            $entry = Activity::create(['ticket_id' => $ticket->id, 'description' => $stored]);
            $expected[$entry->id] = $displayed;
            $this->assertSame($stored, $entry->fresh()->getRawOriginal('description'));
        }
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach ([['/api/v1/tickets/'.$ticket->id, 'activity'], ['/api/v1/activity', 'data']] as [$url, $key]) {
            $entries = $this->getJson($url)->assertOk()->json($key);
            $this->assertEquals($expected, collect($entries)->pluck('description', 'id')->all());
        }
    }

    public function test_workflow_history_provides_ticket_subjects_for_navigation(): void
    {
        $ticket = Ticket::factory()->create(['subject' => 'Help with importing reviews']);
        Automation::factory()->create(['name' => 'Welcome', 'trigger' => 'ticket.created']);
        app(AutomationEngine::class)->run($ticket, 'ticket.created');
        $this->actingAs(User::factory()->create(['role' => 'admin']))->postJson('/api/v1/tickets/'.$ticket->id.'/follow-ups', [
            'body' => 'Following up', 'due_at' => now()->addHour()->toIso8601String(), 'cancel_on_reply' => false,
        ])->assertCreated();

        $this->getJson('/api/v1/workflows')->assertOk()
            ->assertJsonPath('runs.0.subject', $ticket->subject)->assertJsonPath('runs.0.ticket_id', $ticket->id)
            ->assertJsonPath('follow_ups.0.subject', $ticket->subject)->assertJsonPath('follow_ups.0.ticket_id', $ticket->id);
    }
}
