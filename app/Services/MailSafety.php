<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Mailbox;
use App\Models\Message;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MailSafety
{
    public const HOLD_REASON = 'Sending paused for review. Release this reply individually after an administrator reactivates sending.';

    private function state(bool $lock = false): object
    {
        $query = DB::table('mail_safety')->where('id', 1);

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    private function failures(object $state): Builder
    {
        return DB::table('mail_delivery_attempts')->where('failure_epoch', $state->epoch)
            ->where('failed_at', '>=', now()->subMinutes($state->window_minutes));
    }

    public function status(): array
    {
        $state = $this->state();

        return ['paused' => $state->paused_at !== null, 'paused_at' => $state->paused_at, 'reason' => $state->reason,
            'threshold' => $state->threshold, 'window_minutes' => $state->window_minutes, 'recent_failures' => $this->failures($state)->count(),
            'held_count' => Message::where('delivery', 'held')->whereHas('ticket', fn ($ticket) => $ticket->inInbox())->count(), 'epoch' => $state->epoch];
    }

    public function prepare(?Mailbox $mailbox): array
    {
        $state = $this->state();

        return ['delivery' => $state->paused_at ? 'held' : ($mailbox?->sending_enabled ? 'queued' : 'saved'),
            'sending_epoch' => $state->epoch, 'delivery_error' => $state->paused_at ? self::HOLD_REASON : null];
    }

    /** Atomically admit a single attempt; messages already in flight may finish. */
    public function begin(Message $message): bool
    {
        return DB::transaction(function () use ($message) {
            $state = $this->state(true);
            $message->refresh();
            if ($message->kind !== 'outbound' || $message->delivery !== 'queued') {
                return false;
            }
            if ($state->paused_at || (int) $message->sending_epoch !== (int) $state->epoch) {
                $message->update(['delivery' => 'held', 'delivery_error' => self::HOLD_REASON]);

                return false;
            }
            $mailbox = $message->ticket->mailbox;
            app(TranslationPolicy::class)->holdIfNeeded($message);
            if ($message->delivery === 'translation_pending') {
                return false;
            }
            if ($message->ticket->merged_into_id) {
                $message->update(['delivery' => 'held', 'delivery_error' => 'This ticket was merged. Reply in the main conversation.']);

                return false;
            }
            $recipients = array_values(array_unique(array_map(fn ($email) => mb_strtolower(trim($email)), [$message->ticket->requester_email, ...($message->ticket->cc ?? [])])));
            $isAgentReply = $message->user_id !== null && $message->rule_name === null;
            foreach ($recipients as $recipient) {
                if ($reason = app(SenderPolicy::class)->restriction($recipient, $isAgentReply)) {
                    $message->update(['delivery' => 'suppressed', 'delivery_error' => $reason]);

                    return false;
                }
            }
            if (! $mailbox?->sending_enabled || ! $mailbox->smtp_host) {
                $message->update(['delivery' => 'saved']);

                return false;
            }
            $attemptId = (string) Str::uuid();
            $externalId = $attemptId.'@'.substr(strrchr($mailbox->email, '@'), 1);
            DB::table('mail_delivery_attempts')->insert(['id' => $attemptId, 'message_id' => $message->id, 'mailbox_id' => $mailbox->id,
                'external_id' => $externalId, 'recipient' => mb_strtolower($message->ticket->requester_email), 'recipients' => json_encode($recipients), 'created_at' => now()]);
            $message->update(['delivery' => 'sending', 'attempt_id' => $attemptId, 'mailbox_id' => $mailbox->id, 'external_id' => $externalId]);

            return true;
        }, 5);
    }

    public function beginRecovery(Mailbox $mailbox, string $email, int $epoch, ?string $attemptId = null): ?string
    {
        return DB::transaction(function () use ($mailbox, $email, $epoch, $attemptId) {
            $state = $this->state(true);
            if ($state->paused_at || (int) $state->epoch !== $epoch || ! $mailbox->sending_enabled || ! $mailbox->smtp_host || app(SenderPolicy::class)->restriction($email)) {
                return null;
            }
            $id = $attemptId ?? (string) Str::uuid();
            if (DB::table('mail_delivery_attempts')->where('id', $id)->exists()) {
                return null;
            }
            DB::table('mail_delivery_attempts')->insert(['id' => $id, 'mailbox_id' => $mailbox->id, 'external_id' => $id.'@'.substr(strrchr($mailbox->email, '@'), 1), 'recipient' => mb_strtolower($email), 'recipients' => json_encode([mb_strtolower($email)]), 'created_at' => now()]);

            return $id;
        }, 5);
    }

    public function recordFailure(string $attemptId, string $kind, string $error): void
    {
        DB::transaction(function () use ($attemptId, $kind, $error) {
            $state = $this->state(true);
            $attempt = DB::table('mail_delivery_attempts')->where('id', $attemptId)->first();
            if (! $attempt || $attempt->failed_at) {
                return;
            }
            DB::table('mail_delivery_attempts')->where('id', $attemptId)->update(['failed_at' => now(), 'failure_epoch' => $state->epoch, 'failure_kind' => $kind, 'error' => $error]);
            Message::where('attempt_id', $attemptId)->update(['delivery' => 'failed', 'delivery_error' => $error]);
            $message = Message::find($attempt->message_id);
            Activity::create(['ticket_id' => $message?->ticket_id, 'description' => ($kind === 'bounce' ? 'Bounce received' : 'Outgoing delivery failed').($message ? ' for #'.$message->ticket_id : '')]);
            $this->evaluate($state);
        }, 5);
    }

    private function evaluate(object $state): void
    {
        if (! $state->paused_at && $this->failures($state)->count() >= $state->threshold) {
            $this->pause('Automatic stop: '.$state->threshold.' bounces or delivery failures within '.$state->window_minutes.' minutes.');
        }
    }

    private function pause(string $reason, ?int $userId = null): void
    {
        DB::table('mail_safety')->where('id', 1)->update(['paused_at' => now(), 'reason' => $reason]);
        Message::where('kind', 'outbound')->where('delivery', 'queued')->update(['delivery' => 'held', 'delivery_error' => self::HOLD_REASON]);
        Activity::create(['user_id' => $userId, 'description' => 'All outgoing email paused. '.$reason]);
    }

    public function configure(int $threshold, int $windowMinutes, int $userId): void
    {
        DB::transaction(function () use ($threshold, $windowMinutes, $userId) {
            $this->state(true);
            DB::table('mail_safety')->where('id', 1)->update(['threshold' => $threshold, 'window_minutes' => $windowMinutes]);
            Activity::create(['user_id' => $userId, 'description' => "Bounce protection set to {$threshold} failures within {$windowMinutes} minutes."]);
            $this->evaluate($this->state());
        }, 5);
    }

    public function manualPause(int $userId): void
    {
        DB::transaction(function () use ($userId) {
            if (! $this->state(true)->paused_at) {
                $this->pause('Paused by an administrator for review.', $userId);
            }
        }, 5);
    }

    public function resume(int $userId, string $review, int $expectedEpoch): void
    {
        DB::transaction(function () use ($userId, $review, $expectedEpoch) {
            $state = $this->state(true);
            abort_unless($state->paused_at && (int) $state->epoch === $expectedEpoch, 409, 'Sending status changed. Refresh and review it again.');
            Message::where('kind', 'outbound')->where('delivery', 'queued')->update(['delivery' => 'held', 'delivery_error' => self::HOLD_REASON]);
            DB::table('mail_safety')->where('id', 1)->update(['paused_at' => null, 'reason' => null, 'epoch' => $state->epoch + 1]);
            Activity::create(['user_id' => $userId, 'description' => 'Sending reactivated after review: '.$review]);
        }, 5);
    }
}
