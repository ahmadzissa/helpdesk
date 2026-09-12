<?php

namespace Tests\Feature;

use App\Jobs\SendTicketReply;
use App\Models\Mailbox;
use App\Models\Ticket;
use App\Models\User;
use App\Services\MailSafety;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class LocalReplyDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['env'] = 'local';
        config(['queue.default' => 'database']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->withSession(['_token' => 'local-send-test'])->withHeader('X-CSRF-TOKEN', 'local-send-test');
    }

    private function connectedTicket(): Ticket
    {
        return Ticket::factory()->create(['mailbox_id' => Mailbox::factory()->create([
            'sending_enabled' => true, 'incoming_enabled' => true, 'smtp_host' => 'smtp.example.com',
        ])->id]);
    }

    public function test_local_reply_sends_after_the_request_without_a_worker_and_cannot_send_twice(): void
    {
        $ticket = $this->connectedTicket();
        $mailer = app('mail.manager')->mailer('array');
        Mail::shouldReceive('build')->once()->andReturn($mailer);
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', ['body' => 'Your order is ready.'])->assertOk();
        $reply = $ticket->messages()->firstOrFail();
        $this->assertSame('sent', $reply->delivery);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('mail_delivery_attempts', 1);
        $this->assertDatabaseHas('mail_delivery_attempts', ['id' => $reply->attempt_id, 'recipient' => $ticket->requester_email, 'failed_at' => null]);
        $this->getJson('/api/v1/tickets/'.$ticket->id)->assertOk()->assertJsonPath('ticket.messages.0.delivery', 'sent');
        SendTicketReply::dispatch($reply)->afterCommit();
        app(DeferredCallbackCollection::class)->invoke();
        $this->assertCount(1, $mailer->getSymfonyTransport()->messages());
        $this->assertDatabaseCount('mail_delivery_attempts', 1);
    }

    public function test_production_keeps_replies_in_the_worker_queue(): void
    {
        $this->app['env'] = 'production';
        $ticket = $this->connectedTicket();
        Mail::shouldReceive('build')->never();
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', ['body' => 'Your order is ready.'])->assertOk();
        $this->assertSame('queued', $ticket->messages()->firstOrFail()->delivery);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('mail_delivery_attempts', 0);
    }

    public function test_local_smtp_failure_is_visible_and_still_triggers_receive_only_mode(): void
    {
        $ticket = $this->connectedTicket();
        $safety = app(MailSafety::class);
        $safety->configure(1, 15, auth()->id());
        Mail::shouldReceive('build')->once()->andThrow(new RuntimeException('Do not expose smtp-secret'));
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', ['body' => 'Your order is ready.'])->assertOk();
        $reply = $ticket->messages()->firstOrFail();
        $this->assertSame('failed', $reply->delivery);
        $this->assertStringNotContainsString('smtp-secret', $reply->delivery_error);
        $this->assertTrue($safety->status()['paused']);
        $this->assertSame(1, $safety->status()['recent_failures']);
        $this->assertTrue($ticket->mailbox->fresh()->incoming_enabled);
        $this->assertDatabaseCount('jobs', 0);
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', ['body' => 'Another reply.'])->assertOk();
        $this->assertSame('held', $ticket->messages()->reorder()->latest('id')->firstOrFail()->delivery);
        $this->assertDatabaseCount('mail_delivery_attempts', 1);
    }

    public function test_private_notes_and_disabled_mailboxes_do_not_send_locally(): void
    {
        $ticket = $this->connectedTicket();
        Mail::shouldReceive('build')->never();
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', ['body' => 'For our team.', 'private' => true])->assertOk();
        $ticket->mailbox->update(['sending_enabled' => false]);
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', ['body' => 'Saved for later.'])->assertOk();
        $this->assertSame('saved', $ticket->messages()->reorder()->latest('id')->firstOrFail()->delivery);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('mail_delivery_attempts', 0);
    }
}
