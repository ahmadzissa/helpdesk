<?php

namespace Tests\Feature;

use App\Jobs\SyncMailbox;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\User;
use App\Services\EmailContent;
use App\Services\ImapInbox;
use App\Services\IncomingMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;
use Webklex\PHPIMAP\Message as ImapMessage;

class EmailRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_images_default_to_visible_and_administrators_can_persist_the_display_setting(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/v1/workspace')->assertOk()->assertJsonPath('settings.general.show_email_images', true);
        $settings = ['name' => 'Support', 'timezone' => 'UTC'];
        $this->putJson('/api/v1/settings', [...$settings, 'show_email_images' => false])->assertOk()->assertJsonPath('show_email_images', false);
        $this->getJson('/api/v1/workspace')->assertJsonPath('settings.general.show_email_images', false);
        $this->putJson('/api/v1/settings', $settings)->assertOk()->assertJsonPath('show_email_images', false);
        $this->putJson('/api/v1/settings', [...$settings, 'show_email_images' => true])->assertOk()->assertJsonPath('show_email_images', true);
        $this->putJson('/api/v1/settings', [...$settings, 'show_email_images' => 'invalid'])->assertUnprocessable();
        $this->actingAs(User::factory()->create(['role' => 'agent']))->putJson('/api/v1/settings', [...$settings, 'show_email_images' => false])->assertForbidden();
        $this->getJson('/api/v1/workspace')->assertJsonPath('settings.general.show_email_images', true);
    }

    private function htmlMessage(string $html): Message
    {
        $message = Message::factory()->create(['body' => 'Plain alternative']);
        $message->forceFill(['email_html' => $html])->save();

        return $message;
    }

    public function test_html_is_rendered_without_executing_email_markup_or_loading_trackers(): void
    {
        $message = $this->htmlMessage('<div dir="rtl"><p>مرحبا <b>world</b></p><a href="https://example.com/?x=1&amp;y=2" onclick="evil()">Order</a><table><tr><td colspan="2">Details</td></tr></table>'
            .'<script>alert(1)</script><style>body{display:none}</style><iframe src="https://evil.example"></iframe><svg onload="evil()"><script>evil()</script></svg><form><input autofocus></form>'
            .'<a href="javascript:alert(1)">bad</a><img src="data:image/svg+xml,evil"><img src="file:///secret"><img src="//example.com/image">'
            .'<img src="https://example.com/pixel" width="1" height="1"><img src="https://example.com/hidden" style="height:1px">'
            .'<img src="https://example.com/photo.png" onerror="evil()" width="900"><span style="position:fixed;background-image:url(https://evil.example);color:#123456;font-weight:bold">safe</span></div>');
        $html = app(EmailContent::class)->render($message);
        foreach (['<b>world</b>', 'مرحبا', 'href="https://example.com/?x=1&amp;y=2"', 'colspan="2"', 'dir="rtl"', 'data-email-src="https://example.com/photo.png"', 'color:#123456', 'font-weight:bold'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        foreach (['<script', '<style', '<iframe', '<svg', '<form', 'onclick', 'onerror', 'javascript:', 'data:image', 'file://', 'position:', 'background-image:', '/pixel', '/hidden'] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $html);
        }
        $this->assertStringNotContainsString(' src="https://', $html);
        $this->assertArrayNotHasKey('email_html', $message->toArray());
    }

    public function test_plain_email_links_are_clickable_and_literal_html_is_escaped(): void
    {
        $message = Message::factory()->create(['body' => "Hello\nhttps://example.com/order?a=1&b=2\n<script>evil()</script>"]);
        $html = app(EmailContent::class)->render($message);
        $this->assertStringContainsString('href="https://example.com/order?a=1&amp;b=2"', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_mime_import_keeps_html_and_authenticated_cid_images_without_duplicates(): void
    {
        Storage::fake('local');
        $box = Mailbox::factory()->create(['incoming_enabled' => true]);
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jYtQAAAAASUVORK5CYII=';
        $raw = "From: Customer <customer@example.com>\r\nMessage-ID: <formatted@example.com>\r\nSubject: Picture\r\nMIME-Version: 1.0\r\nContent-Type: multipart/related; boundary=parts\r\n\r\n"
            ."--parts\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n<p>Hello <strong>team</strong></p><img src=\"cid:photo@example.com\">\r\n"
            ."--parts\r\nContent-Type: image/png; name=photo.png\r\nContent-ID: <photo@example.com>\r\nContent-Disposition: inline; filename=photo.png\r\nContent-Transfer-Encoding: base64\r\n\r\n{$png}\r\n--parts--\r\n";
        $inbox = Mockery::mock(ImapInbox::class);
        $inbox->shouldReceive('receive')->twice()->andReturnUsing(function ($mailbox, $consume) use ($raw): void {
            $consume(ImapMessage::fromString($raw));
        });
        (new SyncMailbox($box->id))->handle(app(IncomingMail::class), $inbox);
        (new SyncMailbox($box->id))->handle(app(IncomingMail::class), $inbox);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('mail_import_receipts', 1);
        $message = Message::firstOrFail();
        $this->assertSame('photo@example.com', $message->attachments[0]['content_id']);
        $this->assertSame('image/png', $message->attachments[0]['mime']);
        $path = '/api/v1/attachments/'.$message->id.'/0/inline';
        $this->getJson($path)->assertUnauthorized();
        $this->actingAs(User::factory()->create());
        $this->getJson('/api/v1/tickets/'.$message->ticket_id)->assertOk()->assertJsonMissingPath('ticket.messages.0.email_html')
            ->assertJsonPath('ticket.messages.0.translation_format', 'html');
        $html = app(EmailContent::class)->render($message);
        $this->assertStringContainsString('src="'.$path.'"', $html);
        $this->assertStringContainsString('<strong>team</strong>', $html);
        $this->get($path)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        Storage::disk('local')->put($message->attachments[0]['path'], '<svg onload="evil()"></svg>');
        $this->get($path)->assertNotFound();
    }

    public function test_translations_keep_safe_html_in_both_save_and_ticket_responses(): void
    {
        $message = $this->htmlMessage('<p>Hola <strong>equipo</strong></p><img src="https://example.com/picture.png">');
        $this->actingAs(User::factory()->create());
        $body = '<p>Hello <strong>team</strong></p><img data-email-src="https://example.com/picture.png"><img src="x" onerror="evil()"><script>evil()</script>';
        $response = $this->putJson('/api/v1/messages/'.$message->id.'/translation', ['body' => $body, 'body_format' => 'html', 'source_language' => 'es', 'target_language' => 'en', 'source_hash' => hash('sha256', $message->body)])->assertOk();
        $html = $response->json('translation.body_html');
        $this->assertStringContainsString('<strong>team</strong>', $html);
        $this->assertStringContainsString('data-email-src="https://example.com/picture.png"', $html);
        $this->assertStringNotContainsString('evil()', $html);
        $this->getJson('/api/v1/tickets/'.$message->ticket_id)->assertOk()->assertJsonPath('ticket.messages.0.translation.body_html', $html);
    }
}
