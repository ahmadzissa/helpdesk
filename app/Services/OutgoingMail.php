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
        $html = Message::renderBody($message->body, 'outbound');
        foreach (DB::table('inline_images')->where('message_id', $message->id)->get() as $image) {
            $cid = $image->id.'@relay.inline';
            $part = DataPart::fromPath(Storage::disk('local')->path($image->path), $image->name, $image->mime)->asInline();
            $part->setContentId($cid);
            $email->addPart($part);
            $html = str_replace('/api/v1/inline-images/'.$image->id, 'cid:'.$cid, $html);
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
}
