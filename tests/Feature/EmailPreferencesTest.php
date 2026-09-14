<?php

namespace Tests\Feature;

use App\Jobs\SendTicketReply;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Ticket;
use App\Services\MailSafety;
use App\Services\SenderPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
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
        $this->get($url)->assertOk()->assertSee('Support email enabled')->assertSee('areviews-logo.png');
        $this->assertDatabaseCount('recipient_suppressions', 0);
        $this->post($url, ['preference' => 'stop'])->assertOk()->assertSee('Email stopped');
        $this->post($url, ['preference' => 'stop'])->assertOk();
        $this->get($url)->assertOk()->assertSee('Resume support email');
        $this->assertDatabaseCount('delivery_events', 1);
        $this->post($url, ['preference' => 'resume'])->assertOk()->assertSee('Support email enabled');
        $this->post($url, ['preference' => 'resume'])->assertOk();
        $this->assertDatabaseCount('recipient_suppressions', 0);
        $this->assertDatabaseCount('delivery_events', 2);
        $this->post($url, ['preference' => 'stop'])->assertOk()->assertSee('Email stopped');
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
}
