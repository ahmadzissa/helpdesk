<?php

namespace Tests\Feature;

use App\Jobs\SendTicketReply;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Services\IncomingMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TicketWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_merge_preserves_history_cancels_follow_ups_and_routes_future_replies_to_parent(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $box = Mailbox::factory()->create();
        $parent = Ticket::factory()->create(['mailbox_id' => $box->id]);
        $child = Ticket::factory()->create(['mailbox_id' => $box->id, 'requester_email' => $parent->requester_email]);
        $original = Message::factory()->create(['ticket_id' => $child->id, 'mailbox_id' => $box->id, 'external_id' => 'child@example.com', 'kind' => 'inbound']);
        $queued = Message::factory()->create(['ticket_id' => $child->id, 'kind' => 'outbound', 'delivery' => 'queued']);
        $this->actingAs($user)->postJson('/api/v1/tickets/'.$child->id.'/follow-ups', ['body' => 'Follow', 'due_at' => now()->addHour()->toIso8601String(), 'cancel_on_reply' => false])->assertCreated();
        $this->postJson('/api/v1/tickets/'.$parent->id.'/merge', ['ticket_ids' => [$child->id]])->assertOk();
        $this->assertSame($parent->id, $child->fresh()->merged_into_id);
        $this->assertSame($child->id, $original->fresh()->ticket_id);
        $this->assertSame('held', $queued->fresh()->delivery);
        $this->assertDatabaseHas('follow_ups', ['ticket_id' => $child->id, 'state' => 'cancelled']);
        $this->getJson('/api/v1/tickets/'.$parent->id)->assertJsonCount(2, 'ticket.messages');
        $this->patchJson('/api/v1/tickets/'.$parent->id, ['requester_email' => 'different@example.com'])->assertConflict();
        $this->patchJson('/api/v1/tickets/'.$child->id, ['status' => 'Open'])->assertConflict();
        $this->postJson('/api/v1/tickets/'.$child->id.'/messages', ['body' => 'No'])->assertConflict();
        $this->putJson('/api/v1/tickets/'.$child->id.'/draft', ['body' => 'No', 'private' => false])->assertConflict();
        $this->postJson('/api/v1/messages/'.$queued->id.'/retry')->assertConflict();
        $incoming = app(IncomingMail::class)->import($box, ['external_id' => 'new@example.com', 'from_email' => $parent->requester_email, 'subject' => 'Re', 'body' => 'Further context', 'references' => ['child@example.com']]);
        $this->assertSame($parent->id, $incoming->id);
        $this->getJson('/api/v1/tickets')->assertJsonPath('total', 1);
    }

    public function test_invalid_merge_is_atomic_and_history_includes_old_archived_tickets_case_insensitively(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['requester_email' => 'customer@example.com', 'subject' => 'Order delivery problem']);
        $same = Ticket::factory()->create(['requester_email' => 'CUSTOMER@example.com', 'subject' => 'Re: Order delivery problem']);
        $old = Ticket::factory()->create(['requester_email' => 'customer@example.com', 'status' => 'Closed', 'folder' => 'archive', 'created_at' => now()->subYears(2)]);
        $different = Ticket::factory()->create();
        $this->actingAs($user)->getJson('/api/v1/tickets/'.$ticket->id.'/history')->assertJsonPath('history.total', 2)->assertJsonPath('open.0.similar', true);
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/merge', ['ticket_ids' => [$same->id, $different->id]])->assertUnprocessable();
        $this->assertNull($same->fresh()->merged_into_id);
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/merge', ['ticket_ids' => [$ticket->id]])->assertUnprocessable();
    }

    public function test_inline_images_are_private_bind_to_one_message_and_embed_in_outgoing_mail(): void
    {
        Queue::fake();
        Storage::fake('local');
        $user = User::factory()->create();
        $other = User::factory()->create();
        $box = Mailbox::factory()->create(['sending_enabled' => true, 'smtp_host' => 'smtp.example.com']);
        $ticket = Ticket::factory()->create(['mailbox_id' => $box->id]);
        $file = UploadedFile::fake()->image('example.png', 20, 20);
        $image = $this->actingAs($user)->post('/api/v1/tickets/'.$ticket->id.'/inline-images', ['image' => $file], ['Accept' => 'application/json'])->assertCreated()->json();
        $this->actingAs($other)->get($image['url'])->assertNotFound();
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', ['body' => $image['markdown']])->assertUnprocessable();
        $this->assertDatabaseCount('messages', 0);
        $this->actingAs($user)->postJson('/api/v1/tickets/'.$ticket->id.'/messages', ['body' => 'Hello '.$image['markdown']])->assertOk();
        $this->get($image['url'])->assertOk()->assertHeader('Content-Type', 'image/png');
        $message = $ticket->messages()->first();
        $mailer = app('mail.manager')->mailer('array');
        Mail::shouldReceive('build')->once()->andReturn($mailer);
        (new SendTicketReply($message))->handle();
        $sent = $mailer->getSymfonyTransport()->messages()->first()->getOriginalMessage();
        $this->assertStringContainsString('cid:'.$image['id'].'@relay.inline', $sent->getHtmlBody());
        $this->assertStringNotContainsString('/api/v1/inline-images/', $sent->getHtmlBody());
        $this->assertCount(1, $sent->getAttachments());
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', ['body' => $image['markdown']])->assertUnprocessable();
        $otherTicket = Ticket::factory()->create();
        $this->postJson('/api/v1/tickets/'.$otherTicket->id.'/messages', ['body' => $image['markdown']])->assertUnprocessable();
        $this->post('/api/v1/tickets/'.$ticket->id.'/inline-images', ['image' => UploadedFile::fake()->createWithContent('evil.svg', '<svg onload="alert(1)"/>')], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    public function test_cancelled_follow_up_never_creates_a_message(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create();
        $url = '/api/v1/tickets/'.$ticket->id.'/follow-ups';
        $id = $this->actingAs($user)->postJson($url, ['body' => 'Do not send', 'due_at' => now()->addMinutes(5)->toIso8601String(), 'cancel_on_reply' => false])->assertCreated()->json('id');
        $this->deleteJson($url.'/'.$id)->assertOk();
        $this->travel(10)->minutes();
        $this->artisan('helpdesk:automate')->assertSuccessful();
        $this->assertDatabaseCount('messages', 0);
        $this->deleteJson($url.'/'.$id)->assertConflict();
    }
}
