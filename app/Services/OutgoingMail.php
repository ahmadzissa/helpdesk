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
        $content = $this->messageHtml($message, $email);
        if ($message->kind === 'outbound') {
            [$greeting, $closing, $team] = $this->replySalutation($message);
            $content = '<p dir="auto" style="margin:0 0 24px;font-size:20px;font-weight:700">'.e($greeting).'</p>'
                .$content.'<p dir="auto" style="margin:24px 0 0">'.e($closing).'<br>'.e($team).'</p>';
        }
        $previous = $this->previousMessage($message);
        $attempt = DB::table('mail_delivery_attempts')->where('id', $message->attempt_id)->first();
        $preferencesUrl = null;
        if ($attempt) {
            $preferencesUrl = $this->link('mail.optout', $attempt->id);
            $email->getHeaders()->addTextHeader('List-Unsubscribe', '<'.$preferencesUrl.'>');
        }
        $logo = DataPart::fromPath(public_path('areviews-logo.png'), 'areviews-logo.png', 'image/png')->asInline();
        $logo->setContentId('areviews-logo@relay.brand');
        $email->addPart($logo);

        return view('outgoing-reply', [
            'content' => $content,
            'previousContent' => $previous ? $this->messageHtml($previous, $email) : null,
            'previousAttribution' => $previous ? $this->attribution($previous) : null,
            'preferencesUrl' => $preferencesUrl,
            'recipient' => $attempt?->recipient,
            'trackingUrl' => $attempt && (WorkspaceSetting::find('mail_policy')?->value['track_opens'] ?? true)
                ? $this->link('mail.open', $attempt->id) : null,
        ])->render();
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
        $reply = $message->body;
        if ($message->kind === 'outbound') {
            [$greeting, $closing, $team] = $this->replySalutation($message);
            $reply = $greeting."\n\n".$reply."\n\n".$closing."\n".$team;
        }
        $previous = $this->previousMessage($message);
        if (! $previous) {
            return $reply;
        }
        $body = $previous->kind === 'inbound' ? app(EmailReplyContent::class)->text($previous->body) : $previous->body;
        if ($previous->kind === 'inbound' && $previous->email_html) {
            $html = app(EmailContent::class)->render($previous);
            $body = trim(html_entity_decode(strip_tags(preg_replace('/<br\s*\/?\s*>|<\/(?:p|div|li|tr|h[1-6])>/i', "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return $reply."\n\n".$this->attribution($previous)."\n".preg_replace('/^/m', '> ', $body);
    }

    /** @return array{string, string, string} */
    private function replySalutation(Message $message): array
    {
        $context = $message->translation_context ?? [];
        $language = empty($context['send_original']) ? ($context['target'] ?? 'en') : ($context['source'] ?? 'en');
        $language = strtolower(explode('-', $language)[0]);
        [$hello, $closing, $team] = match ($language) {
            'ar' => ['مرحباً', 'مع أطيب التحيات،', 'فريق Areviews'],
            'es' => ['Hola', 'Saludos cordiales,', 'El equipo de Areviews'],
            'fr' => ['Bonjour', 'Cordialement,', 'L’équipe Areviews'],
            'de' => ['Hallo', 'Mit freundlichen Grüßen', 'Ihr Areviews-Team'],
            'pt' => ['Olá', 'Atenciosamente,', 'Equipe Areviews'],
            'it' => ['Ciao', 'Cordiali saluti,', 'Il team Areviews'],
            'nl' => ['Hallo', 'Met vriendelijke groet,', 'Het Areviews-team'],
            'tr' => ['Merhaba', 'Saygılarımızla,', 'Areviews Ekibi'],
            default => ['Hello', 'Best regards,', 'Areviews Team'],
        };
        $name = trim(preg_replace('/\s+/u', ' ', $message->ticket->requester_name ?? ''));
        if (filter_var($name, FILTER_VALIDATE_EMAIL)) {
            $name = '';
        }
        $greeting = $hello.($name !== '' ? ' '.$name : '').($language === 'ar' ? '،' : ',');

        return [$greeting, $closing, $team];
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
