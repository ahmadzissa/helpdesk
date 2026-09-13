<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\EmailHeaders;
use App\Services\MailSafety;
use App\Services\OutgoingMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SendTicketReply implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Message $message)
    {
        if (app()->environment('local')) {
            $this->onConnection('deferred');
        }
    }

    public function handle(): void
    {
        $safety = app(MailSafety::class);
        if (! $safety->begin($this->message)) {
            return;
        }
        $ticket = $this->message->ticket;
        $mailbox = $ticket->mailbox;
        try {
            $externalId = $this->message->external_id;
            $mailer = app(OutgoingMail::class)->mailer($mailbox);
            $mailer->raw(app(OutgoingMail::class)->text($this->message), function (\Illuminate\Mail\Message $mail) use ($ticket, $mailbox, $externalId) {
                $email = $mail->getSymfonyMessage();
                $email->html(app(OutgoingMail::class)->html($this->message, $email));
                $email->getHeaders()->addIdHeader('Message-ID', $externalId);
                $parent = app(OutgoingMail::class)->previousMessage($this->message);
                if ($parent?->external_id) {
                    $email->getHeaders()->addIdHeader('In-Reply-To', $parent->external_id);
                }
                if ($this->message->rule_name) {
                    $email->getHeaders()->addTextHeader('Auto-Submitted', 'auto-replied');
                }
                $attempt = DB::table('mail_delivery_attempts')->where('id', $this->message->attempt_id)->firstOrFail();
                $recipients = json_decode($attempt->recipients, true);
                $mail->to($attempt->recipient)->from($mailbox->email, $mailbox->name)->replyTo($mailbox->email)->subject('Re: '.app(EmailHeaders::class)->decode($this->message->translated_subject ?? $ticket->subject).' [#'.$ticket->id.']');
                $cc = array_values(array_diff($recipients, [$attempt->recipient]));
                if ($cc !== []) {
                    $mail->cc($cc);
                }
                foreach ($this->message->attachments ?? [] as $file) {
                    $mail->attach(Storage::disk('local')->path($file['path']), ['as' => $file['name']]);
                }
            });
            Message::whereKey($this->message->id)->where('delivery', 'sending')->where('attempt_id', $this->message->attempt_id)->update(['delivery' => 'sent', 'delivery_error' => null, 'sent_at' => now()]);
            $ticket->forceFill(['workflow_activity_at' => now()])->save();
            DB::table('mail_delivery_attempts')->where('id', $this->message->attempt_id)->update(['sent_at' => now()]);
        } catch (Throwable $exception) {
            $safety->recordFailure($this->message->attempt_id, 'smtp', 'SMTP delivery failed. Check the mailbox connection settings and recipient address.');
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $message = $this->message->fresh();
        if ($message?->delivery === 'sending' && $message->attempt_id) {
            app(MailSafety::class)->recordFailure($message->attempt_id, 'smtp', 'Delivery could not be confirmed. Check the provider before retrying.');
        } elseif ($message?->delivery === 'queued') {
            $this->message->update(['delivery' => 'failed', 'delivery_error' => 'The mail job failed. Check the queue and mailbox settings.']);
        }
    }
}
