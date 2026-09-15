<?php

namespace App\Services;

use App\Models\Activity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EmailPreferences
{
    public function update(string $attemptId, string $preference): bool
    {
        return DB::transaction(function () use ($attemptId, $preference): bool {
            DB::table('mail_safety')->where('id', 1)->lockForUpdate()->firstOrFail();
            $attempt = DB::table('mail_delivery_attempts')->where('id', $attemptId)->lockForUpdate()->firstOrFail();
            $email = mb_strtolower(trim($attempt->recipient));
            $query = DB::table('recipient_suppressions')->where('email', $email);
            $suppression = (clone $query)->lockForUpdate()->first();

            if ($preference === 'stop' && ! $suppression) {
                $changed = DB::table('recipient_suppressions')->insertOrIgnore([
                    'email' => $email, 'reason' => 'opt_out',
                    'notes' => 'Recipient confirmed the email preference link.',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } elseif ($preference === 'resume' && $suppression?->reason === 'opt_out') {
                $changed = $query->where('reason', 'opt_out')->delete();
            } else {
                return false;
            }

            if (! $changed) {
                return false;
            }

            $detail = $preference === 'stop' ? 'Recipient disabled automatic and scheduled email notifications. Direct agent replies remain enabled.' : 'Recipient resumed automatic and scheduled email notifications.';
            DB::table('delivery_events')->insert([
                'attempt_id' => $attemptId, 'event_key' => 'preference:'.Str::uuid(),
                'type' => $preference === 'stop' ? 'opt_out' : 'opt_in', 'recipient' => $email,
                'detail' => $detail, 'occurred_at' => now(), 'created_at' => now(),
            ]);
            Activity::create(['description' => mb_substr($detail.' '.$email, 0, 255)]);

            return true;
        }, 5);
    }

    public function status(string $email): string
    {
        $email = mb_strtolower(trim($email));
        $suppression = DB::table('recipient_suppressions')->where('email', $email)->first();
        if ($suppression && $suppression->reason !== 'opt_out') {
            return 'restricted';
        }
        if (app(SenderPolicy::class)->classification($email) === 'blocked') {
            return 'restricted';
        }

        return $suppression ? 'stopped' : 'enabled';
    }
}
