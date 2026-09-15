<?php

namespace Tests\Feature;

use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkspaceSetting;
use App\Services\IncomingMail;
use App\Services\TicketCustomFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ShopifyDomainDetectionTest extends TestCase
{
    use RefreshDatabase;

    private function configureField(): string
    {
        $key = (string) Str::uuid();
        WorkspaceSetting::create(['key' => 'custom_fields', 'value' => ['revision' => 1, 'fields' => [['key' => $key, 'name' => 'Shopify Domain']]]]);

        return $key;
    }

    #[TestWith(['My store is dinorun.myshopify.com&#x20;', 'dinorun.myshopify.com'])]
    #[TestWith(['https://DINO-RUN.myshopify.com/admin?x=1', 'dino-run.myshopify.com'])]
    #[TestWith(['<a href="https://dinorun.myshopify.com">Visit store</a>', 'dinorun.myshopify.com'])]
    #[TestWith(['(dinorun.myshopify.com). Again: DINORUN.myshopify.com', 'dinorun.myshopify.com'])]
    #[TestWith(['.myshopify.com', null])]
    #[TestWith(['myshopify.com', null])]
    #[TestWith(['dinorun.myshopify.com.evil.test', null])]
    #[TestWith(['dinorun.myshopify.com%2eevil.test', null])]
    #[TestWith(['sub.dinorun.myshopify.com', null])]
    #[TestWith(['-dinorun.myshopify.com invalid_.myshopify.com bad-.myshopify.com', null])]
    #[TestWith(['first.myshopify.com and second.myshopify.com', null])]
    public function test_detects_only_complete_unambiguous_store_domains(string $text, ?string $expected): void
    {
        $this->assertSame($expected, app(TicketCustomFields::class)->detectShopifyDomain([$text]));
    }

    public function test_received_email_fills_the_field_and_exposes_it_in_the_ticket(): void
    {
        $key = $this->configureField();
        $ticket = app(IncomingMail::class)->import(Mailbox::factory()->create(), [
            'external_id' => 'shopify-detection@example.com', 'from_email' => 'customer@example.com',
            'subject' => 'Help with my store', 'body' => 'Please help',
            'email_html' => '<p>My store: <a href="https://dinorun.myshopify.com/admin">Visit store</a>&#x20;</p>',
        ]);
        $this->assertSame('dinorun.myshopify.com', $ticket->fresh()->custom_fields[$key]);
        $this->actingAs(User::factory()->create())->getJson('/api/v1/tickets/'.$ticket->id)
            ->assertOk()->assertJsonPath('ticket.custom_fields.'.$key, 'dinorun.myshopify.com');
    }

    public function test_agent_messages_fill_empty_fields_without_replacing_manual_values_or_other_fields(): void
    {
        $key = $this->configureField();
        $ticket = Ticket::factory()->create(['custom_fields' => [$key => null, 'order' => '123']]);
        $this->actingAs(User::factory()->create())->postJson('/api/v1/tickets/'.$ticket->id.'/messages', [
            'body' => 'Store: dinorun.myshopify.com', 'private' => true,
        ])->assertOk()->assertJsonPath('data.custom_fields.'.$key, 'dinorun.myshopify.com');
        $this->assertSame('123', $ticket->fresh()->custom_fields['order']);
        $ticket->update(['custom_fields' => [$key => 'chosen.myshopify.com', 'order' => '123']]);
        Message::factory()->create(['ticket_id' => $ticket->id, 'body' => 'different.myshopify.com']);
        $this->assertSame([$key => 'chosen.myshopify.com', 'order' => '123'], $ticket->fresh()->custom_fields);
    }

    public function test_detection_requires_a_configured_field_and_skips_conflicting_sources(): void
    {
        $ticket = Ticket::factory()->create(['subject' => 'first.myshopify.com']);
        Message::factory()->create(['ticket_id' => $ticket->id, 'body' => 'first.myshopify.com']);
        $this->assertSame([], $ticket->fresh()->custom_fields);
        $key = $this->configureField();
        Message::factory()->create(['ticket_id' => $ticket->id, 'body' => 'second.myshopify.com']);
        $this->assertArrayNotHasKey($key, $ticket->fresh()->custom_fields);
    }

    public function test_backfill_checks_existing_and_merged_messages_without_changing_ticket_activity(): void
    {
        $ticket = Ticket::factory()->create();
        $merged = Ticket::factory()->create();
        $merged->forceFill(['merged_into_id' => $ticket->id])->save();
        Message::factory()->create(['ticket_id' => $merged->id, 'body' => 'dinorun.myshopify.com']);
        $ambiguous = Ticket::factory()->create();
        Message::factory()->create(['ticket_id' => $ambiguous->id, 'body' => 'first.myshopify.com']);
        Message::factory()->create(['ticket_id' => $ambiguous->id, 'body' => 'second.myshopify.com']);
        $key = $this->configureField();
        $activity = $ticket->fresh()->only(['last_activity_at', 'workflow_activity_at', 'status', 'unread']);
        $this->artisan('tickets:fill-shopify-domains', ['--dry-run' => true])->expectsOutput('Would fill 1 Shopify Domain field(s).')->assertSuccessful();
        $this->assertSame([], $ticket->fresh()->custom_fields);
        $this->artisan('tickets:fill-shopify-domains')->expectsOutput('Filled 1 Shopify Domain field(s).')->assertSuccessful();
        $this->assertSame('dinorun.myshopify.com', $ticket->fresh()->custom_fields[$key]);
        $this->assertEquals($activity, $ticket->fresh()->only(array_keys($activity)));
        $this->assertSame([], $merged->fresh()->custom_fields);
        $this->assertSame([], $ambiguous->fresh()->custom_fields);
        $this->artisan('tickets:fill-shopify-domains')->expectsOutput('Filled 0 Shopify Domain field(s).')->assertSuccessful();
    }

    public function test_stale_ticket_instances_cannot_overwrite_a_saved_value(): void
    {
        $key = $this->configureField();
        $ticket = Ticket::factory()->create();
        $ticket->fresh()->update(['custom_fields' => [$key => 'manual.myshopify.com']]);
        $this->assertFalse(app(TicketCustomFields::class)->fillShopifyDomain($ticket, ['dinorun.myshopify.com']));
        $this->assertSame('manual.myshopify.com', $ticket->fresh()->custom_fields[$key]);
    }

    public function test_backfill_can_add_the_field_preserving_existing_definitions_and_revision(): void
    {
        $order = ['key' => (string) Str::uuid(), 'name' => 'Order number'];
        WorkspaceSetting::create(['key' => 'custom_fields', 'value' => ['revision' => 3, 'fields' => [$order]]]);
        $ticket = Ticket::factory()->create(['custom_fields' => [$order['key'] => '123']]);
        Message::factory()->create(['ticket_id' => $ticket->id, 'body' => 'dinorun.myshopify.com']);
        $this->artisan('tickets:fill-shopify-domains', ['--create-field' => true, '--dry-run' => true])->assertFailed();
        $this->assertCount(1, app(TicketCustomFields::class)->settings()['fields']);
        $this->artisan('tickets:fill-shopify-domains', ['--create-field' => true])->assertSuccessful();
        $settings = app(TicketCustomFields::class)->settings();
        $this->assertSame(4, $settings['revision']);
        $this->assertSame($order, $settings['fields'][0]);
        $this->assertSame('Shopify Domain', $settings['fields'][1]['name']);
        $this->assertSame([$order['key'] => '123', $settings['fields'][1]['key'] => 'dinorun.myshopify.com'], $ticket->fresh()->custom_fields);
        $this->artisan('tickets:fill-shopify-domains', ['--create-field' => true])->assertSuccessful();
        $this->assertSame($settings, app(TicketCustomFields::class)->settings());
    }
}
