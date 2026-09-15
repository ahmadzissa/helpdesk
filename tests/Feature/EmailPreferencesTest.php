<?php

namespace Tests\Feature;

use App\Jobs\SendTicketReply;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Services\FollowUpRunner;
use App\Services\MailSafety;
use App\Services\SenderPolicy;
use App\Services\WorkflowActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmailPreferencesTest extends TestCase
{
    use RefreshDatabase;

    private function outgoing(): Message
    {
        $mailbox = Mailbox::factory()->create(['sending_enabled' => true, 'smtp_host' => 'smtp.example.com']);
        $ticket = Ticket::factory()->create(['mailbox_id' => $mailbox->id, 'requester_email' => 'customer@example.com']);
        $message = Message::factory()->create(['ticket_id' => $ticket->id, 'kind' => 'outbound', ...app(MailSafety::class)->prepare($mailbox)]);
        app(MailSafety::class)->begin($message);

        return $message;
    }

    public function test_public_page_shows_persisted_preferences_and_supports_repeatable_stop_resume_cycles(): void
    {
        $message = $this->outgoing();
        $url = URL::signedRoute('mail.optout', ['attempt' => $message->attempt_id]);
        $this->get($url)->assertOk()->assertSee('Email notifications enabled')->assertSee('Direct replies from our support agents remain enabled')->assertSee('areviews-logo.png');
        $this->assertDatabaseCount('recipient_suppressions', 0);
        $this->post($url, ['preference' => 'stop'])->assertOk()->assertSee('Automatic and scheduled emails stopped');
        $this->post($url, ['preference' => 'stop'])->assertOk();
        $this->get($url)->assertOk()->assertSee('Resume email notifications');
        $this->assertDatabaseCount('delivery_events', 1);
        $this->post($url, ['preference' => 'resume'])->assertOk()->assertSee('Email notifications enabled');
        $this->post($url, ['preference' => 'resume'])->assertOk();
        $this->assertDatabaseCount('recipient_suppressions', 0);
        $this->assertDatabaseCount('delivery_events', 2);
        $this->post($url, ['preference' => 'stop'])->assertOk()->assertSee('Automatic and scheduled emails stopped');
        $this->assertDatabaseHas('recipient_suppressions', ['email' => 'customer@example.com', 'reason' => 'opt_out']);
        $this->assertDatabaseCount('delivery_events', 3);
    }

    public function test_invalid_expired_and_missing_links_have_safe_error_pages(): void
    {
        $message = $this->outgoing();
        $unsigned = route('mail.optout', ['attempt' => $message->attempt_id]);
        $expired = URL::temporarySignedRoute('mail.optout', now()->subMinute(), ['attempt' => $message->attempt_id]);
        foreach ([$unsigned, $expired] as $url) {
            $this->get($url)->assertForbidden()->assertSee('invalid or has expired')->assertDontSee('customer@example.com');
            $this->post($url, ['preference' => 'stop'])->assertForbidden();
        }
        $this->get(URL::signedRoute('mail.optout', ['attempt' => Str::uuid()]))->assertNotFound()->assertSee('No email preferences have been changed');
        $this->assertDatabaseCount('recipient_suppressions', 0);
    }

    public function test_page_does_not_extend_expiring_links_and_rejects_invalid_choices(): void
    {
        $message = $this->outgoing();
        $url = URL::temporarySignedRoute('mail.optout', now()->addMinutes(10), ['attempt' => $message->attempt_id]);
        $this->get($url)->assertOk()->assertSee('action="'.e($url).'"', false);
        $this->post($url, ['preference' => ['stop']])->assertUnprocessable()->assertSee('Choose whether to stop or resume');
        $this->assertDatabaseCount('recipient_suppressions', 0);
    }

    public function test_preference_changes_require_a_valid_csrf_token(): void
    {
        $message = $this->outgoing();
        $url = URL::signedRoute('mail.optout', ['attempt' => $message->attempt_id]);
        $this->app['env'] = 'local';
        $this->post($url, ['preference' => 'stop'])->assertStatus(419);
        $this->assertDatabaseCount('recipient_suppressions', 0);
        $token = Str::random(40);
        $this->withSession(['_token' => $token])->post($url, ['_token' => $token, 'preference' => 'stop'])->assertOk();
        $this->assertDatabaseHas('recipient_suppressions', ['email' => 'customer@example.com', 'reason' => 'opt_out']);
    }

    public function test_customer_cannot_remove_or_replace_admin_delivery_restrictions(): void
    {
        $message = $this->outgoing();
        $url = URL::signedRoute('mail.optout', ['attempt' => $message->attempt_id]);
        foreach (['manual', 'complaint', 'hard_bounce'] as $reason) {
            app(SenderPolicy::class)->suppress('customer@example.com', $reason);
            $this->get($url)->assertOk()->assertSee('Delivery restricted')->assertDontSee('<form', false);
            $this->post($url, ['preference' => 'stop'])->assertOk();
            $this->post($url, ['preference' => 'resume'])->assertOk()->assertSee('Delivery restricted');
            $this->assertDatabaseHas('recipient_suppressions', ['email' => 'customer@example.com', 'reason' => $reason]);
        }
        $this->assertDatabaseCount('delivery_events', 0);
    }

    public function test_preference_is_bound_to_recipient_and_enforced_before_sending(): void
    {
        $message = $this->outgoing();
        $url = URL::signedRoute('mail.optout', ['attempt' => $message->attempt_id]);
        $this->post($url, ['preference' => 'stop', 'email' => 'someoneelse@example.com'])->assertOk();
        $this->assertDatabaseMissing('recipient_suppressions', ['email' => 'someoneelse@example.com']);
        $queued = Message::factory()->create(['ticket_id' => $message->ticket_id, 'kind' => 'outbound', ...app(MailSafety::class)->prepare($message->ticket->mailbox)]);
        Mail::shouldReceive('build')->never();
        (new SendTicketReply($queued))->handle();
        $this->assertSame('suppressed', $queued->fresh()->delivery);
        $this->post($url, ['preference' => 'resume'])->assertOk();
        $this->assertSame('suppressed', $queued->fresh()->delivery);
        $this->assertNull(app(SenderPolicy::class)->restriction('customer@example.com'));
    }

    public function test_resuming_never_removes_sender_rules_or_reactivates_global_sending(): void
    {
        $message = $this->outgoing();
        $url = URL::signedRoute('mail.optout', ['attempt' => $message->attempt_id]);
        $this->post($url, ['preference' => 'stop'])->assertOk();
        DB::table('sender_rules')->insert(['kind' => 'email', 'value' => 'customer@example.com', 'action' => 'blocked', 'include_subdomains' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('mail_safety')->where('id', 1)->update(['paused_at' => now()]);
        $this->post($url, ['preference' => 'resume'])->assertOk()->assertSee('Delivery restricted');
        $this->assertNotNull(app(SenderPolicy::class)->restriction('customer@example.com'));
        $this->assertTrue(app(MailSafety::class)->status()['paused']);
    }

    public function test_opted_out_customer_and_cc_still_receive_direct_agent_replies(): void
    {
        Queue::fake([SendTicketReply::class]);
        $original = $this->outgoing();
        $ticket = $original->ticket;
        $ticket->update(['cc' => ['cc@example.com']]);
        $url = URL::signedRoute('mail.optout', ['attempt' => $original->attempt_id]);
        $this->post($url, ['preference' => 'stop'])->assertOk();
        app(SenderPolicy::class)->suppress('cc@example.com', 'opt_out');
        $agent = User::factory()->create();

        $this->actingAs($agent)->getJson('/api/v1/tickets/'.$ticket->id)->assertOk()
            ->assertJsonPath('ticket.email_opt_outs', ['cc@example.com', 'customer@example.com']);
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', ['body' => 'A direct answer from support.'])->assertOk();
        $reply = $ticket->messages()->reorder()->latest('id')->firstOrFail();
        $mailer = app('mail.manager')->mailer('array');
        Mail::shouldReceive('build')->once()->andReturn($mailer);
        (new SendTicketReply($reply))->handle();

        $this->assertSame('sent', $reply->fresh()->delivery);
        $sent = $mailer->getSymfonyTransport()->messages()->first()->getOriginalMessage();
        $this->assertSame('customer@example.com', $sent->getTo()[0]->getAddress());
        $this->assertSame('cc@example.com', $sent->getCc()[0]->getAddress());
        $this->post($url, ['preference' => 'resume'])->assertOk();
        $this->getJson('/api/v1/tickets/'.$ticket->id)->assertOk()->assertJsonPath('ticket.email_opt_outs', ['cc@example.com']);
        $this->getJson('/api/v1/tickets/'.Ticket::factory()->create()->id)->assertOk()->assertJsonPath('ticket.email_opt_outs', []);
    }

    public function test_opt_out_blocks_queued_automation_and_agent_created_scheduled_follow_ups(): void
    {
        Queue::fake([SendTicketReply::class]);
        $original = $this->outgoing();
        $ticket = $original->ticket;
        $agent = User::factory()->create();
        $automated = app(WorkflowActions::class)->message($ticket, 'Automatic notification', false, 'Welcome rule');
        $macro = app(WorkflowActions::class)->message($ticket, 'Macro notification', false, 'Follow-up macro', $agent->id);
        $followUp = $this->actingAs($agent)->postJson('/api/v1/tickets/'.$ticket->id.'/follow-ups', [
            'body' => 'Scheduled notification', 'due_at' => now()->addMinute()->toIso8601String(), 'cancel_on_reply' => false,
        ])->assertCreated()->json('id');
        $this->post(URL::signedRoute('mail.optout', ['attempt' => $original->attempt_id]), ['preference' => 'stop'])->assertOk();
        $this->travel(2)->minutes();
        app(FollowUpRunner::class)->run();
        $scheduled = Message::findOrFail(DB::table('follow_ups')->where('id', $followUp)->value('message_id'));
        Mail::shouldReceive('build')->never();

        foreach ([$automated, $macro, $scheduled] as $message) {
            (new SendTicketReply($message))->handle();
            $this->assertSame('suppressed', $message->fresh()->delivery);
            $this->assertNull($message->fresh()->attempt_id);
        }
    }

    public function test_agent_replies_still_obey_delivery_restrictions_and_sender_blocks(): void
    {
        $original = $this->outgoing();
        $agent = User::factory()->create();
        Mail::shouldReceive('build')->never();
        foreach (['manual', 'complaint', 'hard_bounce', 'opt_out'] as $reason) {
            app(SenderPolicy::class)->suppress('customer@example.com', $reason);
            if ($reason === 'opt_out') {
                DB::table('sender_rules')->insert(['kind' => 'email', 'value' => 'customer@example.com', 'action' => 'blocked', 'include_subdomains' => false, 'created_at' => now(), 'updated_at' => now()]);
            }
            $reply = Message::factory()->create(['ticket_id' => $original->ticket_id, 'kind' => 'outbound', 'user_id' => $agent->id, ...app(MailSafety::class)->prepare($original->ticket->mailbox)]);
            (new SendTicketReply($reply))->handle();
            $this->assertSame('suppressed', $reply->fresh()->delivery);
        }
    }
}
