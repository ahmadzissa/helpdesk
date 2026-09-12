<?php

namespace Tests\Feature;

use App\Jobs\SendTicketReply;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\SavedView;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
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
        $this->assertSame('**Hello** customer', $sent->getTextBody());
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
