<?php

namespace Tests\Feature;

use App\Models\Mailbox;
use App\Models\User;
use App\Services\IncomingMail;
use App\Services\MobilePush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobilePushTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private string $installation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        $this->withCredentials();
        Http::preventStrayRequests();
        $this->owner = User::factory()->create();
        $this->installation = (string) Str::uuid();
        config(['mobile.enabled' => true, 'mobile.owner_ids' => [$this->owner->id]]);
    }

    private function register(): void
    {
        $this->actingAs($this->owner)->postJson('/mobile/device', ['installation_id' => $this->installation, 'push_token' => 'ExpoPushToken[phone-one]'])->assertOk();
        $this->withCookie(config('session.cookie'), session()->getId());
    }

    private function mail(array $changes = []): array
    {
        return array_replace(['external_id' => 'mobile-first@example.test', 'from_email' => 'customer@example.test', 'from_name' => 'Customer', 'subject' => 'Help', 'body' => 'Please help'], $changes);
    }

    public function test_registration_requires_login_and_owner_allowlist_and_rejects_invalid_tokens(): void
    {
        $data = ['installation_id' => $this->installation, 'push_token' => 'ExpoPushToken[phone-one]'];
        $this->postJson('/mobile/device', $data)->assertForbidden();
        config(['mobile.owner_ids' => []]);
        $this->actingAs($this->owner)->postJson('/mobile/device', $data)->assertForbidden();
        config(['mobile.owner_ids' => [$this->owner->id]]);
        $this->postJson('/mobile/device', [...$data, 'push_token' => 'not-a-token'])->assertUnprocessable();
        $this->register();
        $this->register();
        $this->assertDatabaseCount('mobile_devices', 1);
    }

    public function test_new_ticket_and_reply_emit_once_and_initial_message_is_not_a_reply(): void
    {
        $this->register();
        $box = Mailbox::factory()->create();
        $import = app(IncomingMail::class);
        $ticket = $import->import($box, $this->mail());
        $import->import($box, $this->mail());
        $this->assertDatabaseCount('mobile_push_deliveries', 1);
        $this->assertDatabaseHas('mobile_push_deliveries', ['type' => 'new_ticket', 'record_id' => (string) $ticket->id]);
        $reply = $this->mail(['external_id' => 'mobile-reply@example.test', 'references' => ['mobile-first@example.test']]);
        $import->import($box, $reply);
        $import->import($box, $reply);
        $this->assertDatabaseCount('mobile_push_deliveries', 2);
        $this->assertDatabaseHas('mobile_push_deliveries', ['type' => 'new_reply']);
    }

    public function test_automated_and_historical_messages_do_not_make_noise(): void
    {
        $this->register();
        $box = Mailbox::factory()->create();
        app(IncomingMail::class)->import($box, $this->mail(['automated' => true]));
        app(IncomingMail::class)->import($box, $this->mail(['external_id' => 'historical@example.test', 'historical' => true]));
        app(IncomingMail::class)->import($box, $this->mail(['external_id' => 'backlog@example.test', 'received_at' => now()->subDay()->toIso8601String()]));
        $this->assertDatabaseCount('mobile_push_deliveries', 0);
    }

    public function test_outbox_rows_rollback_with_their_ticket_transaction(): void
    {
        $this->register();
        $box = Mailbox::factory()->create();
        DB::beginTransaction();
        app(IncomingMail::class)->import($box, $this->mail());
        $this->assertDatabaseCount('mobile_push_deliveries', 1);
        DB::rollBack();
        $this->assertDatabaseCount('mobile_push_deliveries', 0);
        Http::assertNothingSent();
    }

    public function test_logout_revokes_registration_and_cancels_pending_push(): void
    {
        $this->register();
        app(IncomingMail::class)->import(Mailbox::factory()->create(), $this->mail());
        $this->postJson('/api/v1/logout')->assertOk();
        app(MobilePush::class)->process();
        $this->assertDatabaseHas('mobile_push_deliveries', ['status' => 'cancelled']);
        Http::assertNothingSent();
    }

    public function test_delivery_retries_transient_failure_then_checks_receipt_and_revokes_invalid_token(): void
    {
        $this->register();
        app(IncomingMail::class)->import(Mailbox::factory()->create(), $this->mail());
        Http::fake(['exp.host/--/api/v2/push/send' => Http::sequence()->push([], 429)->push(['data' => ['status' => 'ok', 'id' => 'receipt-one']]),
            'exp.host/--/api/v2/push/getReceipts' => Http::response(['data' => ['receipt-one' => ['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']]]])]);
        $push = app(MobilePush::class);
        $push->process();
        $this->assertDatabaseHas('mobile_push_deliveries', ['status' => 'retry', 'attempts' => 1]);
        $this->travel(30)->seconds();
        $push->process();
        $this->assertDatabaseHas('mobile_push_deliveries', ['status' => 'receipt']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/send') && $request['channelId'] === 'new-ticket-v1' && $request['sound'] === 'new_ticket.wav');
        $this->travel(15)->minutes();
        $push->process();
        $this->assertNotNull(DB::table('mobile_devices')->first()->revoked_at);
        $this->assertDatabaseHas('mobile_push_deliveries', ['status' => 'failed', 'last_error' => 'DeviceNotRegistered']);
    }

    public function test_spam_and_token_rotation_cancel_already_queued_messages(): void
    {
        $this->register();
        $ticket = app(IncomingMail::class)->import(Mailbox::factory()->create(), $this->mail());
        $ticket->update(['folder' => 'spam']);
        app(MobilePush::class)->process();
        $this->assertDatabaseHas('mobile_push_deliveries', ['status' => 'cancelled']);
        Http::assertNothingSent();
        $ticket->update(['folder' => 'inbox']);
        app(MobilePush::class)->enqueue('reply:another', 'new_reply', (string) $ticket->id);
        $this->postJson('/mobile/device', ['installation_id' => $this->installation, 'push_token' => 'ExpoPushToken[phone-two]'])->assertOk();
        app(MobilePush::class)->process();
        $this->assertSame(2, DB::table('mobile_push_deliveries')->where('status', 'cancelled')->count());
        Http::assertNothingSent();
    }
}
