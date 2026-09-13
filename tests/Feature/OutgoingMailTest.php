<?php

namespace Tests\Feature;

use App\Jobs\SendTicketReply;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\SavedView;
use App\Models\Ticket;
use App\Models\User;
use App\Services\EmailContent;
use App\Services\OutgoingMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class OutgoingMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_reply_transport_builds_safe_multipart_email_and_thread_headers(): void
    {
        $box = Mailbox::factory()->create(['email' => 'support@example.com', 'smtp_host' => 'smtp.example.com', 'sending_enabled' => true]);
        $ticket = Ticket::factory()->create(['mailbox_id' => $box->id, 'cc' => ['cc@example.com']]);
        $ticket->messages()->create(['body' => 'Question', 'kind' => 'inbound', 'mailbox_id' => $box->id, 'external_id' => 'original@example.com']);
        $message = $ticket->messages()->create(['body' => '**Hello** customer', 'kind' => 'outbound', 'delivery' => 'queued', 'rule_name' => 'Welcome rule']);
        $mailer = app('mail.manager')->mailer('array');
        Mail::shouldReceive('build')->once()->withArgs(fn ($config) => $config['host'] === 'smtp.example.com' && $config['require_tls'] === true)->andReturn($mailer);
        (new SendTicketReply($message))->handle();
        $sent = $mailer->getSymfonyTransport()->messages()->first()->getOriginalMessage();
        $this->assertSame($ticket->requester_email, $sent->getTo()[0]->getAddress());
        $this->assertSame('support@example.com', $sent->getFrom()[0]->getAddress());
        $this->assertSame('cc@example.com', $sent->getCc()[0]->getAddress());
        $this->assertStringStartsWith("**Hello** customer\n\nOn ", $sent->getTextBody());
        $this->assertStringContainsString('> Question', $sent->getTextBody());
        $this->assertStringContainsString('<strong>Hello</strong>', $sent->getHtmlBody());
        $this->assertStringContainsString('original@example.com', $sent->getHeaders()->get('In-Reply-To')->getBodyAsString());
        $this->assertSame('auto-replied', $sent->getHeaders()->get('Auto-Submitted')->getBodyAsString());
        $this->assertSame('sent', $message->fresh()->delivery);
        $this->assertNotNull($message->fresh()->external_id);
        (new SendTicketReply($message))->handle();
        $this->assertCount(1, $mailer->getSymfonyTransport()->messages());
    }

    public function test_transport_failure_sets_failed_state_without_exposing_credentials(): void
    {
        $box = Mailbox::factory()->create(['sending_enabled' => true, 'smtp_host' => 'smtp.example.com']);
        $ticket = Ticket::factory()->create(['mailbox_id' => $box->id]);
        $message = Message::factory()->create(['ticket_id' => $ticket->id, 'kind' => 'outbound', 'delivery' => 'queued']);
        Mail::shouldReceive('build')->once()->andThrow(new \RuntimeException('provider auth error: secret-password'));
        try {
            (new SendTicketReply($message))->handle();
        } catch (\RuntimeException) {
        }
        $this->assertSame('failed', $message->fresh()->delivery);
        $this->assertStringNotContainsString('secret-password', $message->fresh()->delivery_error);
    }

    public function test_reply_includes_only_the_previous_public_message_without_older_history_or_private_content(): void
    {
        $ticket = Ticket::factory()->create();
        Message::factory()->create(['ticket_id' => $ticket->id, 'body' => 'Oldest question']);
        $previous = Message::factory()->create(['ticket_id' => $ticket->id, 'body' => "Latest question\n\nOn Friday Support wrote:\n> Oldest question", 'external_id' => 'previous@example.com']);
        $previous->forceFill(['email_html' => '<p>Latest <strong>question</strong></p><div class="gmail_quote">Oldest question</div>'])->save();
        Message::factory()->create(['ticket_id' => $ticket->id, 'kind' => 'note', 'body' => 'Secret internal note']);
        Message::factory()->create(['ticket_id' => $ticket->id, 'kind' => 'outbound', 'delivery' => 'held', 'body' => 'Unsent draft']);
        $message = Message::factory()->create(['ticket_id' => $ticket->id, 'kind' => 'outbound', 'body' => 'Current reply']);
        Message::factory()->create(['ticket_id' => $ticket->id, 'body' => 'Later arrival']);
        $outgoing = app(OutgoingMail::class);
        $this->assertSame($previous->id, $outgoing->previousMessage($message)->id);
        $html = $outgoing->html($message, new Email);
        $text = $outgoing->text($message);
        $this->assertStringContainsString('Current reply', $html);
        $this->assertStringContainsString('Latest <strong>question</strong>', $html);
        $this->assertStringContainsString('> Latest question', $text);
        foreach (['Oldest question', 'Secret internal note', 'Unsent draft', 'Later arrival'] as $excluded) {
            $this->assertStringNotContainsString($excluded, $html);
            $this->assertStringNotContainsString($excluded, $text);
        }
        $imported = Message::factory()->make();
        $imported->forceFill(['email_html' => '<p>Customer replies again</p>'.$html]);
        $this->assertStringNotContainsString('Latest', app(EmailContent::class)->render($imported));
    }

    public function test_previous_sent_reply_is_eligible_but_an_unrelated_ticket_is_not(): void
    {
        $previous = Message::factory()->create(['kind' => 'outbound', 'delivery' => 'sent', 'body' => 'Previous delivered reply']);
        Message::factory()->create(['body' => 'Unrelated customer']);
        $message = Message::factory()->create(['ticket_id' => $previous->ticket_id, 'kind' => 'outbound', 'body' => 'Current reply']);
        $outgoing = app(OutgoingMail::class);
        $this->assertSame($previous->id, $outgoing->previousMessage($message)->id);
        $this->assertStringContainsString('Previous delivered reply', $outgoing->html($message, new Email));
        $this->assertStringNotContainsString('Unrelated customer', $outgoing->text($message));
        $first = Message::factory()->create(['kind' => 'outbound', 'body' => 'First reply']);
        $this->assertSame('First reply', $outgoing->text($first));
    }

    public function test_previous_customer_images_are_embedded_without_exposing_authenticated_urls(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('ticket-attachments/screenshot', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jYtQAAAAASUVORK5CYII='));
        $previous = Message::factory()->create(['body' => 'Screenshot', 'attachments' => [
            ['path' => 'ticket-attachments/screenshot', 'name' => 'صورة.png', 'mime' => 'image/png', 'content_id' => 'screenshot@example.com', 'size' => 68],
        ]]);
        $previous->forceFill(['email_html' => '<p>Screenshot</p><img src="cid:screenshot@example.com"><img src="https://example.com/photo.png"><div class="gmail_quote">Old history</div>'])->save();
        $message = Message::factory()->create(['ticket_id' => $previous->ticket_id, 'kind' => 'outbound', 'body' => 'Thanks for the screenshot']);
        $email = new Email;
        $html = app(OutgoingMail::class)->html($message, $email);
        $this->assertStringContainsString('src="cid:'.$previous->id.'-0@relay.quoted"', $html);
        $this->assertStringContainsString('src="https://example.com/photo.png"', $html);
        $this->assertStringNotContainsString('/api/v1/attachments/', $html);
        $this->assertStringNotContainsString('Old history', $html);
        $this->assertCount(1, $email->getAttachments());
    }

    public function test_only_failed_messages_in_connected_mailboxes_can_be_retried(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());
        $box = Mailbox::factory()->create();
        $ticket = Ticket::factory()->create(['mailbox_id' => $box->id]);
        $message = Message::factory()->create(['ticket_id' => $ticket->id, 'kind' => 'outbound', 'delivery' => 'failed']);
        $this->postJson('/api/v1/messages/'.$message->id.'/retry')->assertUnprocessable();
        $box->update(['sending_enabled' => true, 'smtp_host' => 'smtp.example.com']);
        $this->postJson('/api/v1/messages/'.$message->id.'/retry')->assertAccepted();
        $this->postJson('/api/v1/messages/'.$message->id.'/retry')->assertUnprocessable();
        Queue::assertPushed(SendTicketReply::class, 1);
    }

    public function test_saved_tags_match_case_insensitively_without_changing_display_names(): void
    {
        $this->actingAs(User::factory()->create());
        $ticket = Ticket::factory()->create(['tags' => ['Orders', 'VIP_100%']]);
        $view = SavedView::factory()->create(['tag' => 'orders']);
        $this->getJson('/api/v1/tickets?view=saved:'.$view->id)->assertJsonPath('total', 1);
        $this->assertSame(['Orders', 'VIP_100%'], $ticket->fresh()->tags);
        $view->update(['tag' => 'vip_100%']);
        $this->getJson('/api/v1/tickets?view=saved:'.$view->id)->assertJsonPath('total', 1);
    }
}
