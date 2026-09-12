<?php

namespace App\Services;

use App\Models\Message;
use Illuminate\Support\Facades\DB;

class DeliveryTracking
{
    public function record(string $attemptId, string $type, string $eventKey, ?string $recipient = null, ?string $detail = null, mixed $occurredAt = null): void
    {
        DB::transaction(function () use ($attemptId, $type, $eventKey, $recipient, $detail, $occurredAt) {
            DB::table('mail_safety')->where('id', 1)->lockForUpdate()->firstOrFail();
            $attempt = DB::table('mail_delivery_attempts')->where('id', $attemptId)->lockForUpdate()->first();
            abort_unless($attempt, 404, 'Unknown delivery attempt.');
            $recipients = json_decode($attempt->recipients ?? 'null', true) ?? [$attempt->recipient];
            $recipient = $recipient ? mb_strtolower(trim($recipient)) : null;
            if ($recipient) {
                abort_unless(in_array($recipient, array_map('mb_strtolower', $recipients), true), 422, 'The recipient does not belong to this delivery attempt.');
            }
            if (! DB::table('delivery_events')->insertOrIgnore(['attempt_id' => $attemptId, 'event_key' => $eventKey, 'type' => $type, 'recipient' => $recipient, 'detail' => $detail, 'occurred_at' => $occurredAt ?? now(), 'created_at' => now()])) {
                return;
            }
            if (in_array($type, ['complaint', 'opt_out'])) {
                abort_unless($recipient, 422, 'A recipient is required.');
                app(SenderPolicy::class)->suppress($recipient, $type, $detail);
            }
            if ($type === 'failed') {
                app(MailSafety::class)->recordFailure($attemptId, 'bounce', $detail ?: 'The receiving server reported delivery failure.');
            }
            if ($type === 'opened' && ! $attempt->opened_at) {
                DB::table('mail_delivery_attempts')->where('id', $attemptId)->update(['opened_at' => $occurredAt ?? now()]);
            }
            if ($type === 'delivered' && ! $attempt->failed_at) {
                $delivered = DB::table('delivery_events')->where('attempt_id', $attemptId)->where('type', 'delivered')->whereNotNull('recipient')->pluck('recipient')->unique();
                if (collect($recipients)->every(fn ($email) => $delivered->contains(mb_strtolower($email)))) {
                    DB::table('mail_delivery_attempts')->where('id', $attemptId)->update(['delivered_at' => $occurredAt ?? now()]);
                    Message::where('attempt_id', $attemptId)->whereIn('delivery', ['sent', 'sending'])->update(['delivery' => 'delivered']);
                }
            }
        }, 5);
    }
}
