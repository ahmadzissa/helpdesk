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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TicketWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_requester_history_separates_recent_tickets_and_searches_only_their_archive(): void
    {
        $this->actingAs(User::factory()->create());
        $ticket = Ticket::factory()->create(['requester_email' => 'customer@example.com']);
        $recent = Ticket::factory()->create(['requester_email' => 'CUSTOMER@example.com', 'folder' => 'inbox', 'status' => 'Closed']);
        $archived = Ticket::factory()->create(['requester_email' => $ticket->requester_email, 'folder' => 'archive', 'subject' => 'Old widget question']);
        Ticket::factory()->create(['requester_email' => $ticket->requester_email, 'folder' => 'archive', 'subject' => 'Billing question']);
        Ticket::factory()->create(['requester_email' => 'another@example.com', 'folder' => 'archive', 'subject' => 'Widget question']);
        Ticket::factory()->create(['requester_email' => $ticket->requester_email, 'folder' => 'trash', 'subject' => 'Widget deleted']);
        $merged = Ticket::factory()->create(['requester_email' => $ticket->requester_email, 'folder' => 'inbox', 'status' => 'Closed', 'merged_into_id' => $ticket->id]);
        $this->getJson('/api/v1/tickets/'.$ticket->id.'/history')->assertOk()->assertJsonCount(2, 'recent')->assertJsonPath('recent.0.id', $merged->id)->assertJsonPath('recent.0.status', 'Closed')->assertJsonPath('recent.1.id', $recent->id);
        $this->getJson('/api/v1/tickets/'.$ticket->id.'/history?folder=archive&q=WIDGET')->assertOk()->assertJsonCount(1, 'history.data')->assertJsonPath('history.data.0.id', $archived->id)->assertJsonCount(2, 'recent');
        $this->getJson('/api/v1/tickets/'.$ticket->id.'/history?folder=trash')->assertUnprocessable();
    }

    public function test_replies_assign_to_the_replying_agent_by_default(): void
    {
        Queue::fake();
        $agent = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($agent);
        foreach ([null, $other->id] as $assignee) {
            $ticket = Ticket::factory()->create(['assignee_id' => $assignee]);
            $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', ['body' => 'I will help with this request.'])
                ->assertOk()->assertJsonPath('data.assignee_id', $agent->id)->assertJsonPath('data.status', 'Pending');
            $this->assertSame($agent->id, $ticket->fresh()->assignee_id);
        }
    }

    public function test_reply_assignment_honors_manual_choices_and_rejects_missing_agents(): void
    {
        Queue::fake();
        $agent = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($agent);
        $ticket = Ticket::factory()->create(['assignee_id' => $agent->id]);
        foreach ([$other->id, null] as $assignee) {
            $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', ['body' => 'A reply with a chosen owner.', 'assignee_id' => $assignee])
                ->assertOk()->assertJsonPath('data.assignee_id', $assignee);
            $this->assertSame($assignee, $ticket->fresh()->assignee_id);
        }
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', ['body' => 'Invalid assignment.', 'assignee_id' => 999999])
            ->assertUnprocessable()->assertJsonValidationErrors('assignee_id');
        $this->assertDatabaseCount('messages', 2);
    }

    public function test_private_notes_do_not_reassign_the_ticket(): void
    {
        Queue::fake();
        $agent = User::factory()->create();
        $other = User::factory()->create();
        $ticket = Ticket::factory()->create(['assignee_id' => $other->id]);
        $this->actingAs($agent)->postJson('/api/v1/tickets/'.$ticket->id.'/messages', ['body' => 'Internal update.', 'private' => true, 'assignee_id' => $agent->id])
            ->assertOk()->assertJsonPath('data.assignee_id', $other->id);
    }

    public function test_customer_replies_default_to_pending_and_honor_an_explicit_status(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());
        $ticket = Ticket::factory()->create(['status' => 'Open']);
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', ['body' => 'Thank you for contacting us.'])
            ->assertOk()->assertJsonPath('data.status', 'Pending');
        $this->assertNull($ticket->fresh()->resolved_at);
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', ['body' => 'Everything is resolved.', 'status' => 'Closed'])
            ->assertOk()->assertJsonPath('data.status', 'Closed');
        $this->assertNotNull($ticket->fresh()->resolved_at);
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', ['body' => 'Following up on your request.'])
            ->assertOk()->assertJsonPath('data.status', 'Pending');
        $this->assertNull($ticket->fresh()->resolved_at);
    }

    public function test_private_notes_preserve_the_current_status_by_default(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());
        $ticket = Ticket::factory()->create(['status' => 'Open']);
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', ['body' => 'Checking internally.', 'private' => true])
            ->assertOk()->assertJsonPath('data.status', 'Open');
        Queue::assertNothingPushed();
    }

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
        $this->getJson('/api/v1/tickets/'.$parent->id)->assertJsonCount(0, 'ticket.messages')->assertJsonPath('ticket.merged_tickets.0.id', $child->id);
        $this->getJson('/api/v1/tickets/'.$child->id)->assertJsonCount(2, 'ticket.messages')->assertJsonPath('ticket.merged_parent.id', $parent->id)->assertJsonPath('ticket.status', 'Closed');
        $this->patchJson('/api/v1/tickets/'.$parent->id, ['requester_email' => 'different@example.com'])->assertConflict();
        $this->patchJson('/api/v1/tickets/'.$child->id, ['status' => 'Open'])->assertConflict();
        $this->postJson('/api/v1/tickets/'.$child->id.'/messages', ['body' => 'No'])->assertConflict();
        $this->putJson('/api/v1/tickets/'.$child->id.'/draft', ['body' => 'No', 'private' => false])->assertConflict();
        $this->postJson('/api/v1/messages/'.$queued->id.'/retry')->assertConflict();
        $incoming = app(IncomingMail::class)->import($box, ['external_id' => 'new@example.com', 'from_email' => $parent->requester_email, 'subject' => 'Re', 'body' => 'Further context', 'references' => ['child@example.com']]);
        $this->assertSame($parent->id, $incoming->id);
        $subjectReply = app(IncomingMail::class)->import($box, ['external_id' => 'subject-reply@example.com', 'from_email' => $parent->requester_email, 'subject' => 'Re: [#'.$child->id.']', 'body' => 'Another reply']);
        $this->assertSame($parent->id, $subjectReply->id);
        $this->getJson('/api/v1/tickets/'.$parent->id)->assertJsonCount(2, 'ticket.messages')->assertJsonPath('ticket.messages.0.body', 'Further context');
        $this->getJson('/api/v1/tickets/'.$child->id)->assertJsonCount(2, 'ticket.messages');
        $this->getJson('/api/v1/tickets')->assertJsonPath('total', 1);
    }

    public function test_merged_tickets_are_searchable_with_relationship_indicators_but_excluded_from_normal_lists_and_counts(): void
    {
        $this->actingAs(User::factory()->create());
        $parent = Ticket::factory()->create(['subject' => 'Matching main ticket']);
        $child = Ticket::factory()->create(['subject' => 'Matching merged ticket', 'requester_email' => $parent->requester_email, 'mailbox_id' => $parent->mailbox_id, 'status' => 'Closed']);
        $child->forceFill(['merged_into_id' => $parent->id, 'last_activity_at' => now()->subDays(90)])->save();
        $this->getJson('/api/v1/tickets')->assertJsonPath('total', 1)->assertJsonPath('tickets.0.merged_tickets_count', 1);
        $this->getJson('/api/v1/tickets?search=Matching')->assertJsonPath('total', 2)->assertJsonPath('counts.all', 1)
            ->assertJsonPath('tickets.0.merged_tickets_count', 1)->assertJsonPath('tickets.1.merged_into_id', $parent->id);
        $this->getJson('/api/v1/tickets?search=merged')->assertJsonPath('total', 1)->assertJsonPath('tickets.0.id', $child->id);
    }

    public function test_merge_candidates_only_include_eligible_requester_tickets_from_the_same_mailbox(): void
    {
        $this->actingAs(User::factory()->create());
        $parent = Ticket::factory()->create();
        $fields = ['requester_email' => $parent->requester_email, 'mailbox_id' => $parent->mailbox_id];
        $eligible = Ticket::factory()->create([...$fields, 'subject' => 'Archived billing', 'folder' => 'archive']);
        Ticket::factory()->create([...$fields, 'folder' => 'trash']);
        Ticket::factory()->create([...$fields, 'folder' => 'spam']);
        Ticket::factory()->create([...$fields, 'merged_into_id' => $parent->id]);
        Ticket::factory()->create([...$fields, 'mailbox_id' => Mailbox::factory()->create()->id]);
        Ticket::factory()->create(['mailbox_id' => $parent->mailbox_id]);
        $this->getJson('/api/v1/tickets/'.$parent->id.'/history?merge_candidates=1&q=billing')->assertOk()
            ->assertJsonPath('history.total', 1)->assertJsonPath('history.data.0.id', $eligible->id);
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
        $this->assertCount(1, array_filter($sent->getAttachments(), fn ($part) => $part->getContentId() === $image['id'].'@relay.inline'));
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', ['body' => $image['markdown']])->assertUnprocessable();
        $otherTicket = Ticket::factory()->create();
        $this->postJson('/api/v1/tickets/'.$otherTicket->id.'/messages', ['body' => $image['markdown']])->assertUnprocessable();
        $this->post('/api/v1/tickets/'.$ticket->id.'/inline-images', ['image' => UploadedFile::fake()->createWithContent('evil.svg', '<svg onload="alert(1)"/>')], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    public function test_image_urls_reuse_existing_images_without_uploading_and_embed_them_for_recipients(): void
    {
        Queue::fake();
        Http::fake();
        Storage::fake('local');
        config(['app.url' => 'https://helpdesk.areviewsapp.com']);
        $user = User::factory()->create();
        $other = User::factory()->create();
        $box = Mailbox::factory()->create(['sending_enabled' => true, 'smtp_host' => 'smtp.example.com']);
        $ticket = Ticket::factory()->create(['mailbox_id' => $box->id]);
        $image = $this->actingAs($user)->post('/api/v1/tickets/'.$ticket->id.'/inline-images', ['image' => UploadedFile::fake()->image('example.png', 20, 20)], ['Accept' => 'application/json'])->assertCreated()->json();
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', ['body' => $image['markdown']])->assertOk();
        $original = DB::table('inline_images')->where('id', $image['id'])->first();
        $files = Storage::disk('local')->allFiles();
        $destination = Ticket::factory()->create(['mailbox_id' => $box->id]);
        $url = config('app.url').$image['url'];
        $remote = 'https://example.com/photo.png?a=1&b=2';
        $body = '![Existing screenshot](<'.$url.'>)'."\n".'![Public image](<'.$remote.'>)';
        $this->actingAs($other)->postJson('/api/v1/tickets/'.$destination->id.'/messages', ['body' => $body])->assertOk();
        $message = $destination->messages()->firstOrFail();
        $this->assertStringContainsString('src="'.$url.'"', Message::renderBody($message->body, 'outbound'));
        $this->assertDatabaseCount('inline_images', 1);
        $this->assertSame($original->message_id, DB::table('inline_images')->where('id', $image['id'])->value('message_id'));
        $this->assertSame($files, Storage::disk('local')->allFiles());
        $mailer = app('mail.manager')->mailer('array');
        Mail::shouldReceive('build')->once()->andReturn($mailer);
        (new SendTicketReply($message))->handle();
        $sent = $mailer->getSymfonyTransport()->messages()->first()->getOriginalMessage();
        $this->assertStringContainsString('src="cid:'.$image['id'].'@relay.inline"', $sent->getHtmlBody());
        $this->assertStringContainsString('src="https://example.com/photo.png?a=1&amp;b=2"', $sent->getHtmlBody());
        $this->assertStringNotContainsString($url, $sent->getHtmlBody());
        $this->assertCount(1, array_filter($sent->getAttachments(), fn ($part) => $part->getContentId() === $image['id'].'@relay.inline'));
        Http::assertNothingSent();
    }

    public function test_image_links_cannot_reuse_another_agents_private_upload_or_a_missing_image(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['app.url' => 'https://helpdesk.areviewsapp.com']);
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create();
        $image = $this->actingAs($user)->post('/api/v1/tickets/'.$ticket->id.'/inline-images', ['image' => UploadedFile::fake()->image('private.png', 20, 20)], ['Accept' => 'application/json'])->assertCreated()->json();
        $body = '![Private](<'.config('app.url').$image['url'].'>)';
        $this->actingAs(User::factory()->create())->postJson('/api/v1/tickets/'.$ticket->id.'/messages', ['body' => $body])->assertUnprocessable();
        $this->assertDatabaseCount('messages', 0);
        Storage::disk('local')->delete(DB::table('inline_images')->where('id', $image['id'])->value('path'));
        $this->actingAs($user)->postJson('/api/v1/tickets/'.$ticket->id.'/messages', ['body' => $body])->assertUnprocessable();
        $this->assertDatabaseCount('messages', 0);
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
