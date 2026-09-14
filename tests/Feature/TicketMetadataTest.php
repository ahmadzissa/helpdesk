<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Mailbox;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversation_includes_its_own_activity_with_actor_names_and_links_to_separate_merged_history(): void
    {
        $agent = User::factory()->create();
        $this->actingAs($agent);
        $ticket = Ticket::factory()->create();
        $child = Ticket::factory()->create();
        $child->forceFill(['merged_into_id' => $ticket->id])->save();
        for ($index = 0; $index < 31; $index++) {
            Activity::create(['ticket_id' => $ticket->id, 'user_id' => $agent->id, 'description' => 'Event '.$index]);
        }
        Activity::create(['ticket_id' => $child->id, 'description' => 'Merged ticket event']);
        Activity::create(['ticket_id' => Ticket::factory()->create()->id, 'description' => 'Unrelated event']);

        $this->getJson('/api/v1/tickets/'.$ticket->id)->assertOk()
            ->assertJsonCount(31, 'activity')
            ->assertJsonPath('activity.0.description', 'Event 0')
            ->assertJsonPath('activity.0.user.name', $agent->name)
            ->assertJsonMissingPath('activity.0.user.email')
            ->assertJsonPath('ticket.merged_tickets.0.id', $child->id)
            ->assertJsonMissing(['description' => 'Merged ticket event'])
            ->assertJsonMissing(['description' => 'Unrelated event']);
        $this->getJson('/api/v1/tickets/'.$child->id)->assertJsonCount(1, 'activity')
            ->assertJsonPath('activity.0.description', 'Merged ticket event')->assertJsonPath('activity.0.user', null);
    }

    public function test_source_cannot_be_changed_after_ticket_creation(): void
    {
        $ticket = Ticket::factory()->create(['source' => 'Email']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->patchJson('/api/v1/tickets/'.$ticket->id, ['source' => 'Phone', 'subject' => 'Changed'])->assertUnprocessable();
        $this->assertSame('Email', $ticket->fresh()->source);
        $this->assertNotSame('Changed', $ticket->fresh()->subject);
        $this->patchJson('/api/v1/tickets/'.$ticket->id, ['source' => 'Email', 'priority' => 'High'])->assertOk();
    }

    public function test_switching_mailboxes_requires_sending_enabled_and_cannot_remove_the_account(): void
    {
        $current = Mailbox::factory()->create(['sending_enabled' => false]);
        $enabled = Mailbox::factory()->create(['sending_enabled' => true]);
        $disabled = Mailbox::factory()->create(['sending_enabled' => false]);
        $ticket = Ticket::factory()->create(['mailbox_id' => $current->id]);
        $this->actingAs(User::factory()->create(['role' => 'agent']));
        $this->patchJson('/api/v1/tickets/'.$ticket->id, ['mailbox_id' => $disabled->id])->assertUnprocessable();
        $this->patchJson('/api/v1/tickets/'.$ticket->id, ['mailbox_id' => null])->assertUnprocessable();
        $this->patchJson('/api/v1/tickets/'.$ticket->id, ['mailbox_id' => $current->id, 'priority' => 'High'])->assertOk();
        $this->patchJson('/api/v1/tickets/'.$ticket->id, ['mailbox_id' => $enabled->id])->assertOk();
        $this->assertSame($enabled->id, $ticket->fresh()->mailbox_id);
        $enabled->update(['sending_enabled' => false]);
        $other = Ticket::factory()->create(['mailbox_id' => $current->id]);
        $this->patchJson('/api/v1/tickets/'.$other->id, ['mailbox_id' => $enabled->id])->assertUnprocessable();
    }

    public function test_merged_conversation_keeps_its_mailbox_even_when_another_account_can_send(): void
    {
        $original = Mailbox::factory()->create();
        $other = Mailbox::factory()->create(['sending_enabled' => true]);
        $parent = Ticket::factory()->create(['mailbox_id' => $original->id]);
        Ticket::factory()->create(['mailbox_id' => $original->id, 'merged_into_id' => $parent->id]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->patchJson('/api/v1/tickets/'.$parent->id, ['mailbox_id' => $other->id])->assertConflict();
        $this->assertSame($original->id, $parent->fresh()->mailbox_id);
    }
}
