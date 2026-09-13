<?php

namespace App\Services;

use App\Models\Mailbox;
use App\Models\Message;
use App\Models\WorkspaceSetting;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

class OutgoingMail
{
    public function link(string $name, string $attempt): string
    {
        $urls = clone app('url');
        $urls->forceRootUrl(config('app.url'));
        $urls->forceScheme(parse_url(config('app.url'), PHP_URL_SCHEME));

        return $urls->signedRoute($name, ['attempt' => $attempt]);
    }

    public function mailer(Mailbox $mailbox): Mailer
    {
        return Mail::build(['transport' => 'smtp', 'scheme' => $mailbox->smtp_encryption === 'ssl' ? 'smtps' : 'smtp',
            'host' => $mailbox->smtp_host, 'port' => $mailbox->smtp_port, 'username' => $mailbox->smtp_username,
            'password' => $mailbox->smtp_password, 'timeout' => 30, 'require_tls' => true]);
    }

    public function html(Message $message, Email $email): string
    {
        $html = '<div dir="auto">'.$this->messageHtml($message, $email).'</div>';
        $previous = $this->previousMessage($message);
        if ($previous) {
            $html .= '<div class="gmail_quote" style="margin-top:24px"><p dir="auto">'.e($this->attribution($previous)).'</p>'
                .'<blockquote type="cite" dir="auto" style="margin:0;padding:0 16px;border-left:2px solid #ddd">'
                .$this->messageHtml($previous, $email).'</blockquote></div>';
        }
        $attempt = DB::table('mail_delivery_attempts')->where('id', $message->attempt_id)->first();
        if ($attempt && (WorkspaceSetting::find('mail_policy')?->value['track_opens'] ?? true)) {
            $html .= '<img src="'.e($this->link('mail.open', $attempt->id)).'" width="1" height="1" alt="" />';
        }
        if ($attempt) {
            $url = $this->link('mail.optout', $attempt->id);
            $html .= '<p style="font-size:12px;color:#777">To stop email to '.e($attempt->recipient).', <a href="'.e($url).'">manage email preferences</a>.</p>';
            $email->getHeaders()->addTextHeader('List-Unsubscribe', '<'.$url.'>');
        }

        return $html;
    }

    public function previousMessage(Message $message): ?Message
    {
        return $message->ticket->messages()->where('id', '<', $message->id)
            ->where(fn ($query) => $query->where('kind', 'inbound')
                ->orWhere(fn ($query) => $query->where('kind', 'outbound')->where('delivery', 'sent')))
            ->reorder()->latest('id')->first();
    }

    public function text(Message $message): string
    {
        $previous = $this->previousMessage($message);
        if (! $previous) {
            return $message->body;
        }
        $body = $previous->kind === 'inbound' ? app(EmailReplyContent::class)->text($previous->body) : $previous->body;
        if ($previous->kind === 'inbound' && $previous->email_html) {
            $html = app(EmailContent::class)->render($previous);
            $body = trim(html_entity_decode(strip_tags(preg_replace('/<br\s*\/?\s*>|<\/(?:p|div|li|tr|h[1-6])>/i', "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return $message->body."\n\n".$this->attribution($previous)."\n".preg_replace('/^/m', '> ', $body);
    }

    private function attribution(Message $message): string
    {
        $author = $message->author_name ?: $message->author_email;
        if ($message->kind === 'outbound') {
            $author = $message->ticket->mailbox?->name ?: $message->ticket->mailbox?->email;
        }

        return 'On '.$message->created_at->format('D, M j, Y \a\t H:i T').' '.($author ?: 'Customer').' wrote:';
    }

    private function messageHtml(Message $message, Email $email): string
    {
        $html = app(EmailContent::class)->render($message);
        foreach (DB::table('inline_images')->where('message_id', $message->id)->get() as $image) {
            $cid = $image->id.'@relay.inline';
            $part = DataPart::fromPath(Storage::disk('local')->path($image->path), $image->name, $image->mime)->asInline();
            $part->setContentId($cid);
            $email->addPart($part);
            $html = str_replace('/api/v1/inline-images/'.$image->id, 'cid:'.$cid, $html);
        }
        foreach ($message->attachments ?? [] as $index => $file) {
            $path = '/api/v1/attachments/'.$message->id.'/'.$index.'/inline';
            if (in_array($file['mime'] ?? '', EmailContent::IMAGE_TYPES, true) && str_contains($html, 'src="'.$path.'"')) {
                $cid = $message->id.'-'.$index.'@relay.quoted';
                $part = DataPart::fromPath(Storage::disk('local')->path($file['path']), $file['name'], $file['mime'])->asInline();
                $part->setContentId($cid);
                $email->addPart($part);
                $html = str_replace('src="'.$path.'"', 'src="cid:'.$cid.'"', $html);
            }
        }

        return str_replace(' data-email-src="', ' src="', $html);
    }
}
