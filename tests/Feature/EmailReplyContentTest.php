<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\User;
use App\Services\EmailContent;
use App\Services\EmailReplyContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmailReplyContentTest extends TestCase
{
    use RefreshDatabase;

    public static function htmlReplies(): array
    {
        return [
            'gmail' => ['<div>not going to use</div><div class="gmail_quote gmail_quote_container"><div>On Fri, Sep 11, 2026 Support wrote:</div><blockquote>Previous reply</blockquote></div>'],
            'screenshot attribution' => ['<div><p>not going to use</p><div>On Fri, Sep 11, 2026 at 4:50 PM<a href="mailto:support@example.com">support@example.com</a> wrote:</div><blockquote><table><tr><td>Previous reply</td></tr></table></blockquote></div>'],
            'apple mail' => ['<div>not going to use</div><blockquote type="cite">Previous reply</blockquote>'],
            'outlook' => ['<div><p>not going to use</p><div id="divRplyFwdMsg">From: Support<br>Sent: Friday<br>To: Customer<br>Subject: Cancel</div></div><div>Previous reply</div>'],
            'thunderbird' => ['<p>not going to use</p><div class="moz-cite-prefix">Support wrote:</div><blockquote>Previous reply</blockquote>'],
            'yahoo' => ['<div>not going to use</div><div class="yahoo_quoted">Previous reply</div>'],
            'line breaks' => ['<div>not going to use<br><br>On Fri, Sep 11, 2026 Support wrote:<br>Previous reply</div>'],
        ];
    }

    #[DataProvider('htmlReplies')]
    public function test_html_replies_show_only_the_new_message(string $html): void
    {
        $message = Message::factory()->make();
        $message->forceFill(['email_html' => $html]);
        $rendered = app(EmailContent::class)->render($message);
        $this->assertSame('not going to use', trim(strip_tags($rendered)));
        $this->assertSame($html, $message->email_html);
    }

    public function test_plain_replies_strip_wrapped_headers_and_quoted_lines(): void
    {
        $content = app(EmailReplyContent::class);
        foreach ([
            "not going to use\r\n\r\nOn Fri, Sep 11, 2026 at 4:50 PM\r\nSupport <support@example.com> wrote:\r\n> Previous reply",
            "not going to use\n\n-----Original Message-----\nPrevious reply",
            "not going to use\n\nFrom: Support\nSent: Friday\nTo: Customer\nSubject: Cancel\nPrevious reply",
            "> Previous reply\n\nnot going to use",
        ] as $body) {
            $this->assertSame('not going to use', $content->text($body));
            $message = Message::factory()->make(['body' => $body]);
            $this->assertSame('not going to use', trim(strip_tags(app(EmailContent::class)->render($message))));
        }
    }

    public function test_new_formatting_images_and_intentional_quotes_are_preserved(): void
    {
        $message = Message::factory()->make();
        $message->forceFill(['email_html' => '<p>Please check <strong>this</strong>.</p><blockquote>The error says unavailable.</blockquote><img src="https://example.com/new.png"><div class="gmail_quote">Previous reply</div><p>Another new detail.</p>']);
        $html = app(EmailContent::class)->render($message);
        $this->assertStringContainsString('<strong>this</strong>', $html);
        $this->assertStringContainsString('<blockquote>The error says unavailable.</blockquote>', $html);
        $this->assertStringContainsString('data-email-src="https://example.com/new.png"', $html);
        $this->assertStringContainsString('Another new detail.', $html);
        $this->assertStringNotContainsString('Previous reply', $html);
        $this->assertSame("On Friday I tried again.\nIt still failed.", app(EmailReplyContent::class)->text("On Friday I tried again.\nIt still failed."));
    }

    public function test_ticket_keeps_separate_messages_and_cleans_existing_originals_and_translations(): void
    {
        $previous = Message::factory()->create(['kind' => 'outbound', 'body' => 'Why are you uninstalling?']);
        $body = "not going to use\n\nOn Fri, Sep 11, 2026 Support wrote:\n> Why are you uninstalling?";
        $message = Message::factory()->create(['ticket_id' => $previous->ticket_id, 'body' => $body]);
        $this->actingAs(User::factory()->create());
        $this->putJson('/api/v1/messages/'.$message->id.'/translation', [
            'body' => $body, 'source_language' => 'en', 'target_language' => 'en', 'source_hash' => hash('sha256', $body),
        ])->assertOk();
        $messages = $this->getJson('/api/v1/tickets/'.$message->ticket_id)->assertOk()->json('ticket.messages');
        $this->assertCount(2, $messages);
        $this->assertSame('Why are you uninstalling?', trim(strip_tags($messages[0]['body_html'])));
        $this->assertSame('not going to use', trim(strip_tags($messages[1]['body_html'])));
        $this->assertSame('not going to use', trim(strip_tags($messages[1]['translation']['body_html'])));
        $this->assertSame('not going to use', $messages[1]['translation_text']);
        $this->assertSame($body, $message->fresh()->body);
    }
}
