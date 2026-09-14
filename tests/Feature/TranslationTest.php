<?php

namespace Tests\Feature;

use App\Jobs\SendTicketReply;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkspaceSetting;
use App\Services\FollowUpRunner;
use App\Services\MailSafety;
use App\Services\TranslationPolicy;
use App\Services\WorkflowActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TranslationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
    }

    private function enableTranslation(): void
    {
        WorkspaceSetting::updateOrCreate(['key' => 'translation'], ['value' => ['incoming' => true, 'target' => 'en', 'outgoing' => true, 'revision' => 1]]);
    }

    private function ticket(): Ticket
    {
        $box = Mailbox::factory()->create(['sending_enabled' => true, 'smtp_host' => 'smtp.example.com']);
        $ticket = Ticket::factory()->create(['mailbox_id' => $box->id, 'requester_email' => 'customer@example.com', 'subject' => 'Necesito ayuda', 'cc' => ['friend@example.com']]);
        DB::table('customer_languages')->insert(['email' => $ticket->requester_email, 'language' => 'es', 'manual' => false]);

        return $ticket;
    }

    private function payload(Ticket $ticket, string $original = 'Hello, we can help.'): array
    {
        return ['body' => 'Hola, podemos ayudar.', 'translation' => [
            'original_body' => $original, 'subject' => 'Necesito ayuda', 'source_language' => 'en',
            'context' => app(TranslationPolicy::class)->context($ticket),
        ]];
    }

    private function cache(Message $message, string $language = 'es', string $target = 'en'): array
    {
        return ['body' => 'Hello, I need help.', 'source_language' => $language, 'target_language' => $target, 'source_hash' => hash('sha256', $message->body)];
    }

    public function test_defaults_and_credentials_are_only_available_to_authenticated_browsers(): void
    {
        config(['services.google_translation.browser_key' => 'synthetic-browser-key-12345']);
        $this->getJson('/api/v1/translation/config')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'agent']));
        $this->getJson('/api/v1/translation/config')->assertOk()->assertJsonPath('settings.target', 'en')->assertJsonPath('settings.incoming', true)
            ->assertJsonPath('settings.outgoing', false)->assertJsonPath('key', 'synthetic-browser-key-12345')->assertHeader('Cache-Control', 'no-store, private');
        $this->putJson('/api/v1/translation/settings', ['incoming' => true, 'outgoing' => true, 'target' => 'ar'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_admin_can_save_language_and_encrypted_key_without_exposing_it_in_bootstrap(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $key = 'synthetic-replacement-browser-key';
        $this->putJson('/api/v1/translation/settings', ['incoming' => true, 'outgoing' => true, 'target' => 'ar', 'key' => $key])
            ->assertOk()->assertJsonPath('settings.revision', 1)->assertJsonPath('has_key', true);
        $this->assertStringNotContainsString($key, WorkspaceSetting::find('translation_key')->getRawOriginal('value'));
        $this->getJson('/api/v1/workspace')->assertJsonMissingPath('settings.translation_key');
        $this->getJson('/api/v1/translation/config')->assertJsonPath('key', $key);
        $this->putJson('/api/v1/translation/settings', ['incoming' => false, 'outgoing' => false, 'target' => 'en', 'key' => null])->assertOk();
        $this->getJson('/api/v1/translation/config')->assertJsonPath('key', $key);
        $this->putJson('/api/v1/translation/settings', ['incoming' => true, 'outgoing' => true, 'target' => 'auto'])->assertUnprocessable();
    }

    public function test_customer_language_is_saved_by_email_and_old_or_agent_messages_cannot_replace_it(): void
    {
        $this->actingAs(User::factory()->create());
        $ticket = $this->ticket();
        $old = Message::factory()->create(['ticket_id' => $ticket->id, 'kind' => 'inbound', 'author_email' => $ticket->requester_email, 'body' => 'Hallo', 'created_at' => now()->subDay()]);
        $other = Ticket::factory()->create(['requester_email' => 'CUSTOMER@example.com']);
        $latest = Message::factory()->create(['ticket_id' => $other->id, 'kind' => 'inbound', 'author_email' => $ticket->requester_email, 'body' => 'Bonjour']);
        $this->putJson('/api/v1/messages/'.$latest->id.'/translation', $this->cache($latest, 'fr'))->assertOk()->assertJsonPath('customer_language.language', 'fr');
        $this->putJson('/api/v1/messages/'.$old->id.'/translation', $this->cache($old, 'de'))->assertOk()->assertJsonPath('customer_language.language', 'fr');
        $agentMessage = Message::factory()->create(['ticket_id' => $ticket->id, 'kind' => 'outbound', 'author_email' => 'agent@example.com']);
        $this->putJson('/api/v1/messages/'.$agentMessage->id.'/translation', $this->cache($agentMessage, 'en'))->assertOk()->assertJsonPath('customer_language.language', 'fr');
        $this->getJson('/api/v1/tickets/'.$ticket->id)->assertJsonPath('ticket.customer_language.language', 'fr')->assertJsonPath('ticket.language_sample.id', $latest->id);
        $this->assertSame('Bonjour', $latest->fresh()->body);
        Http::assertNothingSent();
    }

    public function test_manual_language_survives_detection_until_reset(): void
    {
        $this->actingAs(User::factory()->create());
        $ticket = $this->ticket();
        $message = Message::factory()->create(['ticket_id' => $ticket->id, 'kind' => 'inbound', 'author_email' => $ticket->requester_email]);
        $this->putJson('/api/v1/tickets/'.$ticket->id.'/language', ['language' => 'ar'])->assertOk();
        $this->putJson('/api/v1/messages/'.$message->id.'/translation', $this->cache($message))->assertJsonPath('customer_language.language', 'ar');
        $this->putJson('/api/v1/tickets/'.$ticket->id.'/language', ['language' => null])->assertOk();
        $this->putJson('/api/v1/messages/'.$message->id.'/translation', $this->cache($message))->assertJsonPath('customer_language.language', 'es');
    }

    public function test_different_reading_languages_keep_separate_caches_and_do_not_become_the_customer_language(): void
    {
        $this->actingAs(User::factory()->create());
        $ticket = $this->ticket();
        $message = Message::factory()->create(['ticket_id' => $ticket->id, 'kind' => 'inbound', 'author_email' => $ticket->requester_email]);
        $this->putJson('/api/v1/messages/'.$message->id.'/translation', $this->cache($message, 'es', 'en'))->assertOk();
        $this->putJson('/api/v1/messages/'.$message->id.'/translation', $this->cache($message, 'es', 'ar'))->assertOk()->assertJsonPath('customer_language.language', 'es');
        $this->assertDatabaseCount('message_translations', 2);
        $this->getJson('/api/v1/tickets/'.$ticket->id)->assertJsonPath('ticket.messages.0.translation.target_language', 'en');
        WorkspaceSetting::updateOrCreate(['key' => 'translation'], ['value' => ['target' => 'ar']]);
        $this->getJson('/api/v1/tickets/'.$ticket->id)->assertJsonPath('ticket.messages.0.translation.target_language', 'ar');
    }

    public function test_translation_cache_rejects_stale_content_and_sanitizes_display_html(): void
    {
        $this->actingAs(User::factory()->create());
        $ticket = $this->ticket();
        $message = Message::factory()->create(['ticket_id' => $ticket->id, 'kind' => 'inbound', 'author_email' => $ticket->requester_email]);
        $this->putJson('/api/v1/messages/'.$message->id.'/translation', [...$this->cache($message), 'source_hash' => str_repeat('0', 64)])->assertConflict();
        $this->putJson('/api/v1/messages/'.$message->id.'/translation', [...$this->cache($message), 'body' => '<script>alert(1)</script>'])->assertOk();
        $response = $this->getJson('/api/v1/tickets/'.$ticket->id)->assertOk();
        $this->assertStringNotContainsString('<script>', $response->json('ticket.messages.0.translation.body_html'));
        $this->putJson('/api/v1/tickets/'.$ticket->id.'/subject-translation', ['subject' => 'Need help', 'target_language' => 'en', 'source_hash' => hash('sha256', $ticket->subject)])->assertOk();
        $this->getJson('/api/v1/tickets/'.$ticket->id)->assertJsonPath('ticket.subject_translation.subject', 'Need help');
        $ticket->update(['subject' => 'Otro asunto']);
        $this->getJson('/api/v1/tickets/'.$ticket->id)->assertJsonPath('ticket.subject_translation', null);
        $this->assertDatabaseCount('message_translations', 1);
    }

    public function test_manual_reply_requires_review_and_private_notes_continue_without_translation(): void
    {
        $this->enableTranslation();
        $this->actingAs(User::factory()->create());
        $ticket = $this->ticket();
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', ['body' => 'Untranslated reply'])->assertUnprocessable();
        $this->assertDatabaseCount('messages', 0);
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', ['body' => 'Internal note', 'private' => true])->assertOk();
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', $this->payload($ticket))->assertOk();
        $reply = $ticket->messages()->where('kind', 'outbound')->first();
        $this->assertSame('Hello, we can help.', $reply->original_body);
        $this->assertSame('Hola, podemos ayudar.', $reply->body);
        Queue::assertPushed(SendTicketReply::class, 1);
    }

    public function test_admin_can_enable_automatic_reply_translation_and_older_settings_preserve_it(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/v1/translation/config')->assertJsonPath('settings.auto_send', false);
        $this->putJson('/api/v1/translation/settings', ['incoming' => true, 'outgoing' => true, 'auto_send' => true, 'target' => 'en'])
            ->assertOk()->assertJsonPath('settings.auto_send', true);
        $this->putJson('/api/v1/translation/settings', ['incoming' => false, 'outgoing' => true, 'target' => 'ar'])
            ->assertOk()->assertJsonPath('settings.auto_send', true);
        $this->getJson('/api/v1/workspace')->assertJsonPath('translation.auto_send', true);
        $this->putJson('/api/v1/translation/settings', ['incoming' => true, 'outgoing' => true, 'auto_send' => 'invalid', 'target' => 'en'])
            ->assertUnprocessable();
    }

    public function test_matching_reply_languages_send_the_exact_original_and_stale_languages_are_held(): void
    {
        $this->enableTranslation();
        $this->actingAs(User::factory()->create());
        $ticket = $this->ticket();
        $mailer = app('mail.manager')->mailer('array');
        Mail::shouldReceive('build')->twice()->andReturn($mailer);
        foreach (['en' => "Hello, we can help.\n\nThank you!", 'ar' => "مرحباً، يمكننا مساعدتك.\n\nشكراً لك!"] as $language => $body) {
            DB::table('customer_languages')->where('email', $ticket->requester_email)->update(['language' => $language, 'manual' => true]);
            $payload = ['body' => $body, 'translation' => ['original_body' => $body, 'subject' => $ticket->subject,
                'source_language' => $language, 'context' => app(TranslationPolicy::class)->context($ticket)]];
            $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', [...$payload, 'body' => 'Changed original'])->assertUnprocessable();
            $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', $payload)->assertOk();
            $message = $ticket->messages()->reorder('id', 'desc')->firstOrFail();
            $this->assertTrue($message->translation_context['same_language']);
            (new SendTicketReply($message))->handle();
            $sent = $mailer->getSymfonyTransport()->messages()->last()->getOriginalMessage();
            $this->assertStringStartsWith($body, $sent->getTextBody());
            $this->assertSame($body, $message->fresh()->body);
            $this->assertSame('sent', $message->fresh()->delivery);
            DB::table('customer_languages')->where('email', $ticket->requester_email)->update(['language' => 'fr']);
            $this->assertFalse(app(TranslationPolicy::class)->ready($message->fresh()));
        }
        Http::assertNothingSent();
    }

    public function test_original_language_reply_requires_translation_to_be_disabled(): void
    {
        $this->enableTranslation();
        $this->actingAs(User::factory()->create());
        $ticket = $this->ticket();
        DB::table('customer_languages')->delete();
        Message::factory()->create(['ticket_id' => $ticket->id, 'kind' => 'inbound', 'author_email' => $ticket->requester_email]);
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', ['body' => 'Original reply', 'send_original' => false])->assertUnprocessable();
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', ['body' => 'Original reply', 'send_original' => true])->assertUnprocessable();
        $this->assertSame(0, $ticket->messages()->where('kind', 'outbound')->count());
        Queue::assertNothingPushed();
        WorkspaceSetting::find('translation')->update(['value' => ['outgoing' => false]]);
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', ['body' => 'Original reply', 'send_original' => true])->assertOk();
        $message = $ticket->messages()->where('kind', 'outbound')->firstOrFail();
        $this->assertTrue($message->translation_context['send_original']);
        $this->assertTrue(app(TranslationPolicy::class)->ready($message));
        DB::table('customer_languages')->insert(['email' => $ticket->requester_email, 'language' => 'es', 'manual' => false]);
        $this->assertTrue(app(TranslationPolicy::class)->ready($message->fresh()));
        $mailer = app('mail.manager')->mailer('array');
        Mail::shouldReceive('build')->once()->andReturn($mailer);
        (new SendTicketReply($message))->handle();
        $sent = $mailer->getSymfonyTransport()->messages()->first()->getOriginalMessage();
        $this->assertStringStartsWith("Original reply\n\nOn ", $sent->getTextBody());
        $this->assertSame('Re: '.$ticket->subject.' [#'.$ticket->id.']', $sent->getSubject());
        Http::assertNothingSent();
    }

    public function test_original_language_fallback_obeys_pause_and_rejects_conflicting_translation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $ticket = $this->ticket();
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', [...$this->payload($ticket), 'send_original' => true])->assertUnprocessable();
        app(MailSafety::class)->manualPause($admin->id);
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', ['body' => 'Original reply', 'send_original' => true])->assertOk();
        $message = $ticket->messages()->firstOrFail();
        $this->assertSame('held', $message->delivery);
        Queue::assertNothingPushed();
        $ticket->update(['requester_email' => 'other@example.com']);
        $this->assertFalse(app(TranslationPolicy::class)->ready($message->fresh()));
    }

    public function test_pending_reply_requires_customer_language_translation_without_creating_a_duplicate(): void
    {
        $this->enableTranslation();
        $this->actingAs(User::factory()->create());
        $ticket = $this->ticket();
        $message = app(WorkflowActions::class)->message($ticket, 'Original reply', false, 'Macro');
        $ticket->update(['status' => 'Open']);
        $this->postJson('/api/v1/messages/'.$message->id.'/prepare-translation', ['body' => 'Changed reply', 'send_original' => true])->assertConflict();
        $this->postJson('/api/v1/messages/'.$message->id.'/prepare-translation', ['body' => 'Original reply', 'send_original' => true])
            ->assertUnprocessable();
        $this->assertSame('translation_pending', $message->fresh()->delivery);
        Queue::assertNothingPushed();
        $this->postJson('/api/v1/messages/'.$message->id.'/prepare-translation', $this->payload($ticket, 'Original reply'))
            ->assertOk()->assertJsonPath('delivery', 'queued');
        $this->assertSame('Pending', $ticket->fresh()->status);
        $this->assertDatabaseCount('messages', 1);
        $this->assertSame('Hola, podemos ayudar.', $message->fresh()->body);
        $this->assertTrue(app(TranslationPolicy::class)->ready($message->fresh()));
        Queue::assertPushed(SendTicketReply::class, 1);
        $this->postJson('/api/v1/messages/'.$message->id.'/prepare-translation', ['body' => 'Original reply', 'send_original' => true])->assertConflict();
    }

    public function test_worker_holds_previously_approved_original_replies_when_translation_is_required(): void
    {
        $this->actingAs(User::factory()->create());
        $ticket = $this->ticket();
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', ['body' => 'Original reply', 'send_original' => true])->assertOk();
        $message = $ticket->messages()->firstOrFail();
        $this->assertTrue(app(TranslationPolicy::class)->ready($message));
        WorkspaceSetting::updateOrCreate(['key' => 'translation'], ['value' => ['outgoing' => true, 'revision' => 0]]);
        Mail::shouldReceive('build')->never();
        (new SendTicketReply($message))->handle();
        $this->assertSame('translation_pending', $message->fresh()->delivery);
        $this->assertDatabaseCount('mail_delivery_attempts', 0);
    }

    public function test_html_image_labels_and_titles_remain_visible_after_translation_is_saved(): void
    {
        $this->actingAs(User::factory()->create());
        $ticket = $this->ticket();
        $message = Message::factory()->create(['ticket_id' => $ticket->id, 'kind' => 'inbound', 'body' => 'Hola',
            'email_html' => '<p>Hola</p><img src="https://example.com/image.png" alt="captura" title="pedido">']);
        $this->putJson('/api/v1/messages/'.$message->id.'/translation', [...$this->cache($message), 'body_format' => 'html',
            'body' => '<p>Hello</p><img data-email-src="https://example.com/image.png" alt="screenshot" title="order">'])
            ->assertOk();
        $response = $this->getJson('/api/v1/tickets/'.$ticket->id)->assertOk();
        $html = $response->json('ticket.messages.0.translation.body_html');
        $this->assertStringContainsString('alt="screenshot"', $html);
        $this->assertStringContainsString('title="order"', $html);
        $this->assertStringContainsString('data-email-src="https://example.com/image.png"', $html);
    }

    public function test_changed_recipient_cc_language_subject_or_settings_rejects_the_preview(): void
    {
        $this->enableTranslation();
        $this->actingAs(User::factory()->create());
        $ticket = $this->ticket();
        $payload = $this->payload($ticket);
        foreach (['recipient' => 'someone@example.com', 'cc' => [], 'target' => 'ar', 'subject_hash' => str_repeat('0', 64), 'revision' => 0] as $key => $value) {
            $stale = $payload;
            $stale['translation']['context'][$key] = $value;
            $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', $stale)->assertConflict();
        }
        $bad = $this->payload($ticket, 'Visit https://example.com/orders');
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', $bad)->assertUnprocessable();
        $this->assertDatabaseCount('messages', 0);
        Queue::assertNothingPushed();
    }

    public function test_queue_holds_automation_and_stale_translations_without_contacting_smtp(): void
    {
        $this->enableTranslation();
        $ticket = $this->ticket();
        $message = app(WorkflowActions::class)->message($ticket, 'Automatic follow-up', false, 'Timed follow-up');
        $this->assertSame('translation_pending', $message->delivery);
        Queue::assertNothingPushed();
        $payload = $this->payload($ticket);
        $reply = $ticket->messages()->create(['kind' => 'outbound', 'body' => $payload['body'], ...app(TranslationPolicy::class)->outgoing($ticket, $payload['body'], $payload['translation']), ...app(MailSafety::class)->prepare($ticket->mailbox)]);
        DB::table('customer_languages')->where('email', $ticket->requester_email)->update(['language' => 'ar']);
        Mail::shouldReceive('build')->never();
        (new SendTicketReply($reply))->handle();
        $this->assertSame('translation_pending', $reply->fresh()->delivery);
        $this->assertDatabaseCount('mail_delivery_attempts', 0);
        $this->actingAs(User::factory()->create())->getJson('/api/v1/tickets?view=undelivered')->assertJsonPath('total', 1);
    }

    public function test_review_updates_the_same_unsent_message_and_obeys_the_global_pause(): void
    {
        $this->enableTranslation();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $ticket = $this->ticket();
        $message = app(WorkflowActions::class)->message($ticket, 'Hello, we can help.', false, 'Macro');
        app(MailSafety::class)->manualPause($admin->id);
        $this->postJson('/api/v1/messages/'.$message->id.'/prepare-translation', $this->payload($ticket))->assertOk()->assertJsonPath('delivery', 'held');
        $this->assertDatabaseCount('messages', 1);
        $this->assertSame('Hola, podemos ayudar.', $message->fresh()->body);
        $this->postJson('/api/v1/messages/'.$message->id.'/retry')->assertConflict();
        Queue::assertNothingPushed();
        app(MailSafety::class)->resume($admin->id, 'Reviewed delivery settings', 0);
        $ticket->update(['status' => 'Open']);
        $this->postJson('/api/v1/messages/'.$message->id.'/retry')->assertAccepted();
        $this->assertSame('Pending', $ticket->fresh()->status);
        Queue::assertPushed(SendTicketReply::class, 1);
        $this->postJson('/api/v1/messages/'.$message->id.'/prepare-translation', $this->payload($ticket))->assertConflict();
    }

    public function test_transport_sends_the_reviewed_translation_and_subject_not_the_admin_original(): void
    {
        $this->enableTranslation();
        $this->actingAs(User::factory()->create());
        $ticket = $this->ticket();
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', $this->payload($ticket))->assertOk();
        $message = $ticket->messages()->first();
        $mailer = app('mail.manager')->mailer('array');
        Mail::shouldReceive('build')->once()->andReturn($mailer);
        (new SendTicketReply($message))->handle();
        $sent = $mailer->getSymfonyTransport()->messages()->first()->getOriginalMessage();
        $this->assertSame('Hola, podemos ayudar.', $sent->getTextBody());
        $this->assertSame('Re: Necesito ayuda [#'.$ticket->id.']', $sent->getSubject());
        $this->assertSame('Hello, we can help.', $message->fresh()->original_body);
        Http::assertNothingSent();
    }

    public function test_a_new_customer_email_requires_fresh_detection_before_a_queued_reply_can_leave(): void
    {
        $this->enableTranslation();
        $this->actingAs(User::factory()->create());
        $ticket = $this->ticket();
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', $this->payload($ticket))->assertOk();
        $reply = $ticket->messages()->first();
        Message::factory()->create(['ticket_id' => $ticket->id, 'kind' => 'inbound', 'author_email' => 'CUSTOMER@example.com', 'body' => 'Bonjour']);
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', $this->payload($ticket))->assertConflict();
        Mail::shouldReceive('build')->never();
        (new SendTicketReply($reply))->handle();
        $this->assertSame('translation_pending', $reply->fresh()->delivery);
        $this->assertDatabaseCount('mail_delivery_attempts', 0);
    }

    public function test_due_follow_ups_wait_for_browser_translation_without_creating_duplicate_messages(): void
    {
        $this->enableTranslation();
        $ticket = $this->ticket();
        DB::table('follow_ups')->insert(['ticket_id' => $ticket->id, 'body' => 'Following up', 'due_at' => now()->subMinute(), 'cancel_on_reply' => false, 'inbound_message_id' => 0, 'state' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        app(FollowUpRunner::class)->run();
        app(FollowUpRunner::class)->run();
        $this->assertDatabaseCount('messages', 1);
        $this->assertSame('translation_pending', $ticket->messages()->first()->delivery);
        $this->assertDatabaseHas('follow_ups', ['state' => 'processed', 'result' => 'translation_pending']);
        Queue::assertNothingPushed();
    }
}
