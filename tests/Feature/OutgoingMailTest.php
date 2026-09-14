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

    public function test_reply_links_are_clickable_and_blue_in_the_ticket_and_outgoing_email(): void
    {
        $body = 'Visit https://example.com/order?one=1&two=2 or www.example.org. [Open guide](<https://example.com/guide>)';
        $message = Message::factory()->create(['kind' => 'outbound', 'body' => $body]);
        foreach ([app(EmailContent::class)->render($message), app(OutgoingMail::class)->html($message, new Email)] as $html) {
            $document = new \DOMDocument;
            $document->loadHTML($html);
            $links = $document->getElementsByTagName('a');
            $this->assertCount(3, $links);
            $this->assertSame('https://example.com/order?one=1&two=2', $links->item(0)->getAttribute('href'));
            $this->assertSame('https://www.example.org', $links->item(1)->getAttribute('href'));
            $this->assertSame('Open guide', $links->item(2)->textContent);
            foreach ($links as $link) {
                $this->assertSame('color:#0057d9;text-decoration:underline', $link->getAttribute('style'));
                $this->assertSame('_blank', $link->getAttribute('target'));
                $this->assertSame('noopener noreferrer', $link->getAttribute('rel'));
            }
        }
        $this->assertSame($body, $message->fresh()->body);
    }

    public function test_reply_link_rendering_preserves_code_and_does_not_enable_unsafe_links(): void
    {
        $html = Message::renderBody('`https://example.com/code` [Unsafe](javascript:alert%281%29)', 'outbound');
        $this->assertStringContainsString('<code>https://example.com/code</code>', $html);
        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertStringNotContainsString('href="https://example.com/code"', $html);
    }

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
        $this->assertStringStartsWith('Hello '.$ticket->requester_name.",\n\n**Hello** customer\n\nBest regards,\nAreviews Team\n\nOn ", $sent->getTextBody());
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
        $this->assertSame('Hello '.$first->ticket->requester_name.",\n\nFirst reply\n\nBest regards,\nAreviews Team", $outgoing->text($first));
    }

    public function test_greeting_and_signature_are_only_added_to_delivered_content_and_escape_customer_names(): void
    {
        $ticket = Ticket::factory()->create(['requester_name' => '<img src=x onerror=alert(1)> Ahmad']);
        $message = Message::factory()->create(['ticket_id' => $ticket->id, 'kind' => 'outbound', 'body' => 'Your answer.', 'original_body' => 'Your answer.']);
        $outgoing = app(OutgoingMail::class);
        $html = $outgoing->html($message, new Email);
        $this->assertStringContainsString('Hello &lt;img src=x onerror=alert(1)&gt; Ahmad,', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('Best regards,<br>Areviews Team', $html);
        $this->assertSame($html, $outgoing->html($message, new Email));
        $this->assertSame('Your answer.', $message->fresh()->body);
        $this->assertSame('Your answer.', $message->fresh()->original_body);
        $this->assertStringNotContainsString('Best regards', app(EmailContent::class)->render($message));

        foreach ([null, '', '   ', 'customer@example.com'] as $name) {
            $ticket->update(['requester_name' => $name]);
            $message->unsetRelation('ticket');
            $this->assertSame("Hello,\n\nYour answer.\n\nBest regards,\nAreviews Team", $outgoing->text($message));
        }
    }

    public function test_translated_reply_uses_localized_salutation_and_original_reply_does_not_use_customer_target(): void
    {
        $ticket = Ticket::factory()->create(['requester_name' => 'أحمد']);
        $message = Message::factory()->create(['ticket_id' => $ticket->id, 'kind' => 'outbound', 'body' => 'يمكننا مساعدتك.',
            'translation_context' => ['target' => 'ar']]);
        $outgoing = app(OutgoingMail::class);
        $this->assertSame("مرحباً أحمد،\n\nيمكننا مساعدتك.\n\nمع أطيب التحيات،\nفريق Areviews", $outgoing->text($message));
        $message->update(['body' => 'Your original answer.', 'translation_context' => ['target' => 'ar', 'send_original' => true]]);
        $this->assertStringStartsWith("Hello أحمد,\n\nYour original answer.", $outgoing->text($message));
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
