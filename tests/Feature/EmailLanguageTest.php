<?php

namespace Tests\Feature;

use App\Jobs\SendTicketReply;
use App\Jobs\SyncMailbox;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Services\EmailHeaders;
use App\Services\ImapInbox;
use App\Services\IncomingMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Webklex\PHPIMAP\Message as ImapMessage;

class EmailLanguageTest extends TestCase
{
    use RefreshDatabase;

    public static function languages(): array
    {
        return [
            'arabic' => ['لدي مشكله في البرنالمج', 'UTF-8'],
            'chinese' => ['程序有问题', 'UTF-8'],
            'japanese' => ['プログラムに問題があります', 'ISO-2022-JP'],
            'korean' => ['프로그램에 문제가 있습니다', 'UTF-8'],
            'hindi' => ['प्रोग्राम में समस्या है', 'UTF-8'],
            'hebrew' => ['יש בעיה בתוכנה', 'UTF-8'],
            'russian' => ['Проблема с программой', 'Windows-1251'],
            'accents and emoji' => ['Problème de connexion ✅', 'UTF-8'],
        ];
    }

    #[DataProvider('languages')]
    public function test_mime_import_and_reply_preserve_international_subjects_and_bodies(string $subject, string $charset): void
    {
        $box = Mailbox::factory()->create(['incoming_enabled' => true, 'sending_enabled' => true, 'smtp_host' => 'smtp.example.com']);
        $encoded = '=?'.$charset.'?B?'.base64_encode(mb_convert_encoding($subject, $charset, 'UTF-8')).'?=';
        $raw = "From: {$encoded} <customer@example.com>\r\nMessage-ID: <language@example.com>\r\nSubject: {$encoded}\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset={$charset}\r\nContent-Transfer-Encoding: base64\r\n\r\n".base64_encode(mb_convert_encoding($subject, $charset, 'UTF-8'));
        $inbox = Mockery::mock(ImapInbox::class);
        $inbox->shouldReceive('receive')->once()->andReturnUsing(function ($mailbox, $consume) use ($raw): void {
            $consume(ImapMessage::fromString($raw));
        });
        (new SyncMailbox($box->id))->handle(app(IncomingMail::class), $inbox);
        $ticket = Ticket::firstOrFail();
        $this->assertSame($subject, $ticket->subject);
        $this->assertSame($subject, $ticket->requester_name);
        $this->assertSame($subject, $ticket->messages()->firstOrFail()->body);
        $this->actingAs(User::factory()->create())->getJson('/api/v1/tickets/'.$ticket->id)->assertOk()->assertJsonPath('ticket.subject', $subject);
        $message = Message::factory()->create(['ticket_id' => $ticket->id, 'kind' => 'outbound', 'body' => $subject, 'delivery' => 'queued']);
        $mailer = app('mail.manager')->mailer('array');
        Mail::shouldReceive('build')->once()->andReturn($mailer);
        (new SendTicketReply($message))->handle();
        $sent = $mailer->getSymfonyTransport()->messages()->first()->getOriginalMessage();
        $this->assertSame('Re: '.$subject.' [#'.$ticket->id.']', $sent->getSubject());
        $this->assertStringStartsWith($subject, $sent->getTextBody());
        $this->assertStringContainsString('dir="auto"', $sent->getHtmlBody());
        $this->assertStringContainsString($subject, $sent->getHtmlBody());
        $roundTrip = ImapMessage::fromString($sent->toString());
        $this->assertSame($sent->getSubject(), app(EmailHeaders::class)->decode((string) $roundTrip->get('subject')));
    }

    public function test_existing_encoded_subjects_display_decoded_without_reimporting(): void
    {
        $encoded = '=?utf-8?B?2YTYr9mKINmF2LTZg9mE2Ycg2YHZiiDYp9mE2KjYsdmG2KfZhNmF2Kw=?=';
        $ticket = Ticket::factory()->create();
        DB::table('tickets')->where('id', $ticket->id)->update(['subject' => $encoded]);
        $expected = 'لدي مشكله في البرنالمج';
        $this->assertSame($expected, $ticket->fresh()->subject);
        $this->actingAs(User::factory()->create())->getJson('/api/v1/tickets/'.$ticket->id)->assertJsonPath('ticket.subject', $expected);
        $this->assertDatabaseCount('tickets', 1);
    }

    public function test_folded_base64_quoted_printable_and_plain_unicode_headers(): void
    {
        $headers = app(EmailHeaders::class);
        $this->assertSame('لدي مشكلة', $headers->decode('=?UTF-8?B?'.base64_encode('لدي ')."?=\r\n\t".'=?UTF-8?B?'.base64_encode('مشكلة').'?='));
        $this->assertSame('Re: Problème résolu', $headers->decode('Re: =?ISO-8859-1?Q?Probl=E8me_r=E9solu?='));
        $this->assertSame('لدي مشكلة ✅', $headers->decode('لدي مشكلة ✅'));
        $this->assertSame('Subject Bcc: hidden@example.com', $headers->decode("Subject\r\nBcc: hidden@example.com"));
    }
}
