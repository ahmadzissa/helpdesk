<?php

namespace Tests\Feature;

use App\Jobs\SendTicketReply;
use App\Jobs\TranslateAutomatedReply;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkspaceSetting;
use App\Services\BackgroundTranslation;
use App\Services\FollowUpRunner;
use App\Services\MailSafety;
use App\Services\SenderPolicy;
use App\Services\TranslationPolicy;
use App\Services\WorkflowActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class BackgroundTranslationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config(['services.google_translation.server_key' => 'synthetic-server-translation-key']);
        WorkspaceSetting::updateOrCreate(['key' => 'translation'], ['value' => ['incoming' => true, 'outgoing' => true, 'target' => 'en', 'auto_send' => false, 'revision' => 1]]);
    }

    private function ticket(): Ticket
    {
        $mailbox = Mailbox::factory()->create(['sending_enabled' => true, 'smtp_host' => 'smtp.example.com']);
        $ticket = Ticket::factory()->create(['mailbox_id' => $mailbox->id, 'requester_email' => 'customer@example.com', 'subject' => 'Necesito ayuda']);
        Message::factory()->create(['ticket_id' => $ticket->id, 'author_email' => $ticket->requester_email, 'kind' => 'inbound', 'body' => 'Hola, necesito ayuda.']);

        return $ticket;
    }

    private function provider(?callable $duringTranslation = null, string $source = 'en'): void
    {
        Http::fake(function (Request $request) use ($duringTranslation, $source) {
            if (str_ends_with($request->url(), '/detect')) {
                return Http::response(['data' => ['detections' => [[['language' => 'es']]]]]);
            }
            if ($duringTranslation) {
                $duringTranslation();
            }

            return Http::response(['data' => ['translations' => array_map(fn (string $text): array => [
                'translatedText' => str_replace('Hello', 'Hola', $text), 'detectedSourceLanguage' => $source,
            ], $request['q'])]]);
        });
    }

    private function translate(Message $message): void
    {
        (new TranslateAutomatedReply($message->id))->handle(app(BackgroundTranslation::class), app(TranslationPolicy::class));
    }

    public function test_first_automatic_reply_detects_translates_and_sends_without_a_browser(): void
    {
        $this->provider();
        $ticket = $this->ticket();
        $message = app(WorkflowActions::class)->message($ticket, 'Hello customer', false, 'Welcome');
        Queue::assertPushed(TranslateAutomatedReply::class, fn ($job): bool => $job->messageId === $message->id && $job->connection === 'database');
        $this->assertNull(auth()->user());
        $this->translate($message);
        $this->assertSame('Hola customer', $message->fresh()->body);
        $this->assertSame('Hello customer', $message->fresh()->original_body);
        $this->assertSame('queued', $message->fresh()->delivery);
        $this->assertDatabaseHas('customer_languages', ['email' => $ticket->requester_email, 'language' => 'es', 'manual' => false]);
        Queue::assertPushed(SendTicketReply::class, fn ($job): bool => $job->message->id === $message->id && $job->connection === 'database');
        $mailer = app('mail.manager')->mailer('array');
        Mail::shouldReceive('build')->once()->andReturn($mailer);
        (new SendTicketReply($message->fresh()))->handle();
        $this->assertSame('sent', $message->fresh()->delivery);
        $this->assertStringContainsString('Hola customer', $mailer->getSymfonyTransport()->messages()->first()->getOriginalMessage()->getTextBody());
        $this->translate($message);
        (new SendTicketReply($message->fresh()))->handle();
        $this->assertDatabaseCount('mail_delivery_attempts', 1);
        Http::assertSentCount(2);
    }

    public function test_scheduled_agent_follow_up_uses_background_translation_once(): void
    {
        $this->provider();
        $ticket = $this->ticket();
        $agent = User::factory()->create();
        DB::table('follow_ups')->insert(['ticket_id' => $ticket->id, 'user_id' => $agent->id, 'body' => 'Hello again', 'due_at' => now()->subMinute(), 'cancel_on_reply' => false, 'inbound_message_id' => 0, 'state' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        app(FollowUpRunner::class)->run();
        app(FollowUpRunner::class)->run();
        $message = $ticket->messages()->where('kind', 'outbound')->firstOrFail();
        $this->translate($message);
        $this->assertSame('Hola again', $message->fresh()->body);
        $this->assertSame($agent->id, $message->user_id);
        Queue::assertPushed(TranslateAutomatedReply::class, 1);
        Queue::assertPushed(SendTicketReply::class, 1);
    }

    public function test_opt_out_and_pause_block_background_work_without_contacting_google(): void
    {
        $ticket = $this->ticket();
        $message = app(WorkflowActions::class)->message($ticket, 'Hello', false, 'Welcome');
        app(SenderPolicy::class)->suppress($ticket->requester_email, 'opt_out');
        $this->translate($message);
        $this->assertSame('suppressed', $message->fresh()->delivery);
        DB::table('recipient_suppressions')->delete();
        $paused = app(WorkflowActions::class)->message($ticket, 'Hello', false, 'Follow-up');
        app(MailSafety::class)->manualPause(User::factory()->create()->id);
        $this->translate($paused);
        $this->assertSame('held', $paused->fresh()->delivery);
        Http::assertNothingSent();
        Queue::assertNotPushed(SendTicketReply::class);
    }

    public function test_opt_out_during_translation_prevents_delivery(): void
    {
        $ticket = $this->ticket();
        $this->provider(fn () => app(SenderPolicy::class)->suppress($ticket->requester_email, 'opt_out'));
        $message = app(WorkflowActions::class)->message($ticket, 'Hello', false, 'Welcome');
        $this->translate($message);
        $this->assertSame('suppressed', $message->fresh()->delivery);
        Queue::assertNotPushed(SendTicketReply::class);
    }

    public function test_failed_translation_stays_unsent_and_the_scheduler_requeues_it(): void
    {
        Http::fake(['*' => Http::response([], 429)]);
        $message = app(WorkflowActions::class)->message($this->ticket(), 'Hello', false, 'Welcome');
        try {
            $this->translate($message);
            $this->fail('A failed translation must not be sent.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Background translation failed', $exception->getMessage());
        }
        $this->assertSame('translation_pending', $message->fresh()->delivery);
        $this->assertSame('Hello', $message->fresh()->body);
        $this->assertStringContainsString('quota', $message->fresh()->delivery_error);
        Queue::assertNotPushed(SendTicketReply::class);
        $this->travel(16)->minutes();
        $this->artisan('helpdesk:automate')->assertSuccessful();
        Queue::assertPushed(TranslateAutomatedReply::class, 2);
    }

    public function test_ticket_changes_during_translation_discard_the_stale_result(): void
    {
        $ticket = $this->ticket();
        $this->provider(fn () => $ticket->update(['subject' => 'Changed subject']));
        $message = app(WorkflowActions::class)->message($ticket, 'Hello', false, 'Welcome');
        try {
            $this->translate($message);
            $this->fail('A stale translation must be retried.');
        } catch (RuntimeException) {
            $this->assertSame('Hello', $message->fresh()->body);
        }
        Queue::assertNotPushed(SendTicketReply::class);
    }

    public function test_translation_preserves_links_images_code_and_line_breaks(): void
    {
        $this->provider();
        $body = "Hello **customer**\n[Hello](https://example.com/help)\n![Hello](https://example.com/image.png)\n`Hello` support@example.com";
        $result = app(BackgroundTranslation::class)->translate($body, 'es');
        $this->assertSame("Hola **customer**\n[Hola](https://example.com/help)\n![Hello](https://example.com/image.png)\n`Hello` support@example.com", $result['text']);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Goog-Api-Key', 'synthetic-server-translation-key'));
    }

    public function test_server_key_is_encrypted_and_never_exposed_to_the_browser(): void
    {
        $key = 'new-synthetic-server-translation-key';
        $this->actingAs(User::factory()->create(['role' => 'admin']))->putJson('/api/v1/translation/settings', [
            'incoming' => true, 'outgoing' => true, 'target' => 'en', 'server_key' => $key,
        ])->assertOk()->assertJsonPath('has_server_key', true);
        $this->assertSame($key, app(TranslationPolicy::class)->serverKey());
        $this->assertStringNotContainsString($key, WorkspaceSetting::find('translation_server_key')->getRawOriginal('value'));
        $this->getJson('/api/v1/workspace')->assertOk()->assertJsonMissingPath('settings.translation_server_key')->assertDontSee($key);
        $this->getJson('/api/v1/translation/config')->assertOk()->assertJsonMissingPath('server_key')->assertDontSee($key);
    }

    public function test_saved_customer_language_and_matching_reply_preserve_the_original(): void
    {
        $this->provider(source: 'es');
        $ticket = $this->ticket();
        DB::table('customer_languages')->insert(['email' => $ticket->requester_email, 'language' => 'es', 'manual' => true, 'created_at' => now(), 'updated_at' => now()]);
        $message = app(WorkflowActions::class)->message($ticket, "Hola, podemos ayudar.\n\nGracias.", false, 'Welcome');
        $this->translate($message);
        $this->assertSame($message->body, $message->fresh()->body);
        $this->assertTrue($message->fresh()->translation_context['same_language']);
        Http::assertSentCount(1);
        Queue::assertPushed(SendTicketReply::class, 1);
    }

    public function test_disabling_translation_releases_pending_automation_without_google(): void
    {
        $ticket = $this->ticket();
        $message = app(WorkflowActions::class)->message($ticket, 'Hello', false, 'Welcome');
        $ticket->update(['translation_enabled' => false]);
        $this->translate($message);
        $this->assertSame('queued', $message->fresh()->delivery);
        $this->assertSame('Hello', $message->fresh()->body);
        Http::assertNothingSent();
        Queue::assertPushed(SendTicketReply::class, 1);
    }

    public function test_automation_without_translation_uses_a_durable_sending_queue(): void
    {
        $ticket = $this->ticket();
        $ticket->update(['translation_enabled' => false]);
        $message = app(WorkflowActions::class)->message($ticket, 'Hello', false, 'Welcome');
        Queue::assertPushed(SendTicketReply::class, fn ($job): bool => $job->message->id === $message->id && $job->connection === 'database');
        Queue::assertNotPushed(TranslateAutomatedReply::class);
    }

    public function test_provider_cannot_add_or_change_links_before_delivery(): void
    {
        Http::fake(['*' => Http::response(['data' => ['translations' => [['translatedText' => 'Hola https://wrong.example', 'detectedSourceLanguage' => 'en']]]])]);
        $ticket = $this->ticket();
        DB::table('customer_languages')->insert(['email' => $ticket->requester_email, 'language' => 'es', 'manual' => true, 'created_at' => now(), 'updated_at' => now()]);
        $message = app(WorkflowActions::class)->message($ticket, 'Hello', false, 'Welcome');
        try {
            $this->translate($message);
            $this->fail('Changed links must block delivery.');
        } catch (RuntimeException) {
            $this->assertSame('Hello', $message->fresh()->body);
            $this->assertSame('translation_pending', $message->fresh()->delivery);
        }
        Queue::assertNotPushed(SendTicketReply::class);
    }
}
