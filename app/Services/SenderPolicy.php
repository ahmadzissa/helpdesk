<?php

namespace App\Services;

use App\Models\Activity;
use Illuminate\Support\Facades\DB;

class SenderPolicy
{
    public function classification(string $email): ?string
    {
        $email = mb_strtolower(trim($email));
        $exact = DB::table('sender_rules')->where('kind', 'email')->where('value', $email)->first();
        if ($exact) {
            return $exact->action;
        }
        $domain = substr(strrchr($email, '@') ?: '', 1);
        $rules = DB::table('sender_rules')->where('kind', 'domain')->get()->sortByDesc(fn ($rule) => strlen($rule->value));
        foreach ($rules as $rule) {
            if ($domain === $rule->value || ($rule->include_subdomains && str_ends_with($domain, '.'.$rule->value))) {
                return $rule->action;
            }
        }

        return null;
    }

    public function restriction(string $email): ?string
    {
        $email = mb_strtolower(trim($email));
        $suppression = DB::table('recipient_suppressions')->where('email', $email)->first();
        if ($suppression) {
            return $email.' is suppressed ('.str_replace('_', ' ', $suppression->reason).').';
        }

        return $this->classification($email) === 'blocked' ? $email.' is blocked by a sender rule.' : null;
    }

    public function suppress(string $email, string $reason, ?string $notes = null): void
    {
        $email = mb_strtolower(trim($email));
        DB::table('recipient_suppressions')->upsert([
            'email' => $email, 'reason' => $reason, 'notes' => $notes, 'created_at' => now(), 'updated_at' => now(),
        ], ['email'], ['reason', 'notes', 'updated_at']);
        Activity::create(['description' => mb_substr('Recipient suppressed: '.$email.' ('.$reason.')', 0, 255)]);
    }
}
