<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkspaceSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TicketCustomFieldsTest extends TestCase
{
    use RefreshDatabase;

    private function fields(): array
    {
        $fields = [['key' => (string) Str::uuid(), 'name' => 'Order number'], ['key' => (string) Str::uuid(), 'name' => 'Store URL']];
        WorkspaceSetting::create(['key' => 'custom_fields', 'value' => ['revision' => 1, 'fields' => $fields]]);

        return $fields;
    }

    public function test_only_administrators_define_fields_and_definitions_appear_on_existing_and_new_tickets(): void
    {
        $before = Ticket::factory()->create();
        $fields = [['key' => (string) Str::uuid(), 'name' => 'Order number']];
        $this->getJson('/api/v1/custom-fields')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'agent']))->putJson('/api/v1/custom-fields', ['revision' => 0, 'fields' => $fields])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->putJson('/api/v1/custom-fields', ['revision' => 0, 'fields' => $fields])->assertOk()->assertJsonPath('revision', 1);
        $after = Ticket::factory()->create();
        foreach ([$before, $after] as $ticket) {
            $this->getJson('/api/v1/tickets/'.$ticket->id)->assertOk()->assertJsonPath('ticket.custom_field_definitions', $fields);
        }
        $this->assertSame([], $before->fresh()->custom_fields);
        $this->getJson('/api/v1/custom-fields')->assertOk()->assertJsonPath('fields', $fields);
    }

    public function test_inline_updates_merge_only_the_edited_field_and_can_clear_its_value(): void
    {
        [$order, $store] = $this->fields();
        $ticket = Ticket::factory()->create(['custom_fields' => [$order['key'] => '100', $store['key'] => 'example.com']]);
        $this->actingAs(User::factory()->create(['role' => 'agent']));
        $this->patchJson('/api/v1/tickets/'.$ticket->id, ['custom_fields' => [$order['key'] => '200']])->assertOk();
        $this->assertSame([$order['key'] => '200', $store['key'] => 'example.com'], $ticket->fresh()->custom_fields);
        $this->patchJson('/api/v1/tickets/'.$ticket->id, ['custom_fields' => [$store['key'] => 'new.example.com']])->assertOk();
        $this->patchJson('/api/v1/tickets/'.$ticket->id, ['custom_fields' => [$order['key'] => '']])->assertOk();
        $this->assertSame([$order['key'] => null, $store['key'] => 'new.example.com'], $ticket->fresh()->custom_fields);
        $this->patchJson('/api/v1/tickets/'.$ticket->id, ['custom_fields' => ['Invented field' => 'value']])->assertUnprocessable()->assertJsonValidationErrors('custom_fields');
    }

    public function test_renaming_and_removing_definitions_preserves_values_and_checks_settings_revision(): void
    {
        $fields = $this->fields();
        $ticket = Ticket::factory()->create(['custom_fields' => [$fields[0]['key'] => '12345']]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $fields[0]['name'] = 'Reference number';
        $this->putJson('/api/v1/custom-fields', ['revision' => 1, 'fields' => $fields])->assertOk()->assertJsonPath('revision', 2);
        $this->getJson('/api/v1/tickets/'.$ticket->id)->assertJsonPath('ticket.custom_field_definitions.0.name', 'Reference number')->assertJsonPath('ticket.custom_fields.'.$fields[0]['key'], '12345');
        $this->putJson('/api/v1/custom-fields', ['revision' => 1, 'fields' => []])->assertConflict();
        $this->putJson('/api/v1/custom-fields', ['revision' => 2, 'fields' => []])->assertOk();
        $this->getJson('/api/v1/tickets/'.$ticket->id)->assertJsonPath('ticket.custom_field_definitions', []);
        $this->assertSame('12345', $ticket->fresh()->custom_fields[$fields[0]['key']]);
        $this->patchJson('/api/v1/tickets/'.$ticket->id, ['custom_fields' => [$fields[0]['key'] => 'changed']])->assertUnprocessable();
    }

    public function test_invalid_definitions_and_unconfigured_fields_on_new_tickets_are_rejected(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $fields = [['key' => (string) Str::uuid(), 'name' => 'Order'], ['key' => (string) Str::uuid(), 'name' => 'ORDER']];
        $this->putJson('/api/v1/custom-fields', ['revision' => 0, 'fields' => $fields])->assertUnprocessable();
        $this->putJson('/api/v1/custom-fields', ['revision' => 0, 'fields' => [['key' => '__proto__', 'name' => 'Field']]])->assertUnprocessable();
        $this->postJson('/api/v1/tickets', ['subject' => 'Question', 'body' => 'Help', 'requester_email' => 'customer@example.com', 'custom_fields' => ['unknown' => 'value']])->assertUnprocessable();
        $this->assertDatabaseCount('tickets', 0);
    }
}
