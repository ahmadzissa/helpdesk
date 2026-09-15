<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\BackgroundTranslation;
use App\Services\EmailContent;
use App\Services\MailSafety;
use App\Services\SenderPolicy;
use App\Services\TranslationPolicy;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class TranslateAutomatedReply implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public int $uniqueFor = 900;

    public function __construct(public int $messageId)
    {
        $this->onConnection('database');
    }

    public function uniqueId(): string
    {
        return (string) $this->messageId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(BackgroundTranslation $translator, TranslationPolicy $policy): void
    {
        $lock = Cache::lock('translate-automated-reply:'.$this->messageId, 650);
        if (! $lock->get()) {
            $this->release(60);

            return;
        }
        try {
            $message = DB::transaction(function () use ($policy): ?Message {
                DB::table('mail_safety')->where('id', 1)->lockForUpdate()->firstOrFail();
                $message = Message::with('ticket.mailbox')->lockForUpdate()->find($this->messageId);
                if (! $message || ! $this->eligible($message)) {
                    return null;
                }
                if (! $message->ticket->translation_enabled || ! $policy->settings()['outgoing']) {
                    $message->update($policy->outgoing($message->ticket, $message->original_body ?? $message->body, null, true));
                }
                if ($policy->ready($message)) {
                    $this->queueDelivery($message);

                    return null;
                }

                return $message;
            });
            if (! $message) {
                return;
            }
            $original = $message->original_body ?? $message->body;
            $customer = $policy->customer($message->ticket);
            $sample = $policy->latestInbound($message->ticket);
            if (! $customer?->language || (! $customer->manual && $customer->source_message_id !== $sample?->id)) {
                $language = $translator->detect($sample ? app(EmailContent::class)->text($sample) : $message->ticket->subject);
                DB::transaction(function () use ($policy, $message, $sample, $language): void {
                    if ($sample) {
                        $policy->detect($sample, $language);
                    } elseif (! $policy->latestInbound($message->ticket)) {
                        DB::table('customer_languages')->insertOrIgnore(['email' => $message->ticket->requester_email, 'created_at' => now(), 'updated_at' => now()]);
                        DB::table('customer_languages')->where('email', $message->ticket->requester_email)->where('manual', false)
                            ->update(['language' => $language, 'source_message_id' => null, 'updated_at' => now()]);
                    }
                });
            }
            $context = $policy->context($message->ticket);
            if (! $context['target']) {
                throw new RuntimeException('Customer language is not available yet. Background translation will retry automatically.');
            }
            $translated = $translator->translate($original, $context['target']);
            DB::transaction(function () use ($policy, $original, $context, $translated): void {
                DB::table('mail_safety')->where('id', 1)->lockForUpdate()->firstOrFail();
                $message = Message::with('ticket.mailbox')->lockForUpdate()->find($this->messageId);
                if (! $message || ! $this->eligible($message)) {
                    return;
                }
                if ($context !== $policy->context($message->ticket) || $original !== ($message->original_body ?? $message->body)) {
                    throw new RuntimeException('The ticket changed during translation. Background translation will retry with the latest details.');
                }
                $message->update($policy->outgoing($message->ticket, $translated['text'], [
                    'original_body' => $original, 'subject' => $message->ticket->subject,
                    'source_language' => $translated['source'], 'context' => $context,
                ]));
                $this->queueDelivery($message);
            });
        } catch (Throwable $exception) {
            $detail = $exception instanceof RuntimeException ? $exception->getMessage() : 'Translation could not be completed. Check the server translation settings.';
            Message::whereKey($this->messageId)->where('delivery', 'translation_pending')->whereNull('attempt_id')
                ->update(['delivery_error' => mb_substr('Background translation failed. '.$detail.' Automatic retry is scheduled.', 0, 500)]);
            throw new RuntimeException('Background translation failed for an automated reply. Check its delivery status.');
        } finally {
            $lock->release();
        }
    }

    private function eligible(Message $message): bool
    {
        if ($message->kind !== 'outbound' || $message->rule_name === null || $message->attempt_id || ! in_array($message->delivery, ['queued', 'translation_pending'])) {
            return false;
        }
        $state = DB::table('mail_safety')->where('id', 1)->firstOrFail();
        if ($state->paused_at || (int) $message->sending_epoch !== (int) $state->epoch) {
            $message->update(['delivery' => 'held', 'delivery_error' => MailSafety::HOLD_REASON]);

            return false;
        }
        if ($message->ticket->merged_into_id || in_array($message->ticket->folder, ['spam', 'trash'])) {
            $message->update(['delivery' => 'held', 'delivery_error' => 'The ticket was merged or moved out of active support. Review it before sending.']);

            return false;
        }
        foreach ([$message->ticket->requester_email, ...($message->ticket->cc ?? [])] as $recipient) {
            if ($reason = app(SenderPolicy::class)->restriction($recipient)) {
                $message->update(['delivery' => 'suppressed', 'delivery_error' => $reason]);

                return false;
            }
        }
        if (! $message->ticket->mailbox?->sending_enabled || ! $message->ticket->mailbox->smtp_host) {
            $message->update(['delivery' => 'saved', 'delivery_error' => null]);

            return false;
        }

        return true;
    }

    private function queueDelivery(Message $message): void
    {
        $message->update(app(MailSafety::class)->prepare($message->ticket->mailbox));
        if ($message->delivery === 'queued') {
            SendTicketReply::dispatch($message)->afterCommit();
        }
    }
}
