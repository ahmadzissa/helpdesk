<?php

namespace App\Services;

use App\Models\Ticket;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class WorkflowConditions
{
    public const FIELDS = ['status', 'priority', 'folder', 'source', 'assignee_id', 'team_id', 'mailbox_id', 'subject', 'requester_email', 'requester_domain', 'company', 'tag', 'body', 'sender_trust', 'age_minutes', 'idle_minutes', 'since_customer_minutes', 'since_reply_minutes', 'since_status_minutes', 'since_events_minutes', 'customer_message_count', 'follow_up_count', 'follow_up_created_count'];

    public const OPERATORS = ['eq', 'neq', 'contains', 'not_contains', 'contains_phrase', 'not_contains_phrase', 'starts_with', 'ends_with', 'empty', 'not_empty', 'gte', 'lte'];

    public const EVENTS = ['status_changed', 'message_sent', 'follow_up_sent', 'activity'];

    public function matches(Ticket $ticket, array $conditions, ?int $automationId = null): bool
    {
        if (! array_key_exists('all', $conditions) && ! array_key_exists('any', $conditions)) {
            $subject = $conditions['subject_contains'] ?? '';
            unset($conditions['subject_contains']);

            return (! $subject || str_contains(mb_strtolower($ticket->subject), mb_strtolower($subject)))
                && Ticket::whereKey($ticket->id)->matching($conditions)->exists();
        }
        $all = $conditions['all'] ?? [];
        $any = $conditions['any'] ?? [];

        return collect($all)->every(fn ($condition) => $this->test($ticket, $condition, $automationId))
            && ($any === [] || collect($any)->contains(fn ($condition) => $this->test($ticket, $condition, $automationId)));
    }

    private function test(Ticket $ticket, array $condition, ?int $automationId): bool
    {
        $field = $condition['field'];
        $value = match ($field) {
            'requester_domain' => substr(strrchr($ticket->requester_email, '@'), 1),
            'tag' => $ticket->tags ?? [],
            'body' => $ticket->messages()->where('kind', 'inbound')->reorder()->latest('id')->value('body') ?? '',
            'sender_trust' => app(SenderPolicy::class)->classification($ticket->requester_email) ?? 'neutral',
            'age_minutes' => $this->minutes($ticket->created_at),
            'idle_minutes' => $this->minutes($ticket->last_activity_at),
            'since_customer_minutes' => $this->minutes($ticket->messages()->where('kind', 'inbound')->max('created_at')),
            'since_reply_minutes' => $this->minutes($ticket->messages()->where('kind', 'outbound')->max('created_at')),
            'since_status_minutes' => $this->minutes($ticket->status_changed_at),
            'since_events_minutes' => $this->sinceEvents($ticket, $condition['events'] ?? []),
            'customer_message_count' => $ticket->messages()->where('kind', 'inbound')->count(),
            'follow_up_count' => $this->followUps($ticket, $condition, $automationId)->whereNotNull('messages.sent_at')->count(),
            'follow_up_created_count' => $this->followUps($ticket, $condition, $automationId)->where('follow_ups.state', '!=', 'cancelled')->count(),
            default => $ticket->{$field},
        };
        $op = $condition['operator'];
        $wanted = (string) ($condition['value'] ?? '');
        if (! ($condition['case_sensitive'] ?? false)) {
            $wanted = mb_strtolower($wanted);
        }
        $empty = $value === null || $value === '' || $value === [];
        if ($op === 'empty' || $op === 'not_empty') {
            return $op === 'empty' ? $empty : ! $empty;
        }
        if ($value === null) {
            return $op === 'eq' ? $wanted === 'unassigned' : ($op === 'neq' && $wanted !== 'unassigned');
        }
        if (is_array($value)) {
            $contains = in_array($wanted, array_map('mb_strtolower', $value), true);

            return in_array($op, ['eq', 'contains']) ? $contains : (in_array($op, ['neq', 'not_contains']) && ! $contains);
        }
        $actual = ($condition['case_sensitive'] ?? false) ? (string) $value : mb_strtolower((string) $value);

        return match ($op) {
            'eq' => $actual === $wanted, 'neq' => $actual !== $wanted,
            'contains' => str_contains($actual, $wanted), 'not_contains' => ! str_contains($actual, $wanted),
            'starts_with' => str_starts_with($actual, $wanted), 'ends_with' => str_ends_with($actual, $wanted),
            'contains_phrase' => $this->containsPhrase($actual, $wanted),
            'not_contains_phrase' => ! $this->containsPhrase($actual, $wanted),
            'gte' => is_numeric($value) && is_numeric($wanted) && $value >= $wanted,
            'lte' => is_numeric($value) && is_numeric($wanted) && $value <= $wanted,
            default => false,
        };
    }

    private function containsPhrase(string $actual, string $wanted): bool
    {
        $phrase = preg_quote(trim($wanted), '~');
        $phrase = preg_replace('/\s+/u', '\\s+', $phrase);

        return preg_match('~(?<![\p{L}\p{N}_])'.$phrase.'(?![\p{L}\p{N}_])~u', $actual) === 1;
    }

    private function followUps(Ticket $ticket, array $condition, ?int $automationId): Builder
    {
        $query = DB::table('follow_ups')->leftJoin('messages', 'messages.id', '=', 'follow_ups.message_id')->where('follow_ups.ticket_id', $ticket->id);
        if (($condition['scope'] ?? 'anyone') === 'rule') {
            $query->where('follow_ups.automation_id', $automationId ?? 0);
        }

        return $query;
    }

    /** @param list<string> $events */
    private function sinceEvents(Ticket $ticket, array $events): ?float
    {
        $dates = [];
        foreach ($events as $event) {
            $date = match ($event) {
                'status_changed' => $ticket->status_changed_at,
                'activity' => $ticket->workflow_activity_at,
                'message_sent' => $ticket->messages()->where('kind', 'outbound')->whereNotIn('id', DB::table('follow_ups')->whereNotNull('message_id')->select('message_id'))->max('sent_at'),
                'follow_up_sent' => $this->followUps($ticket, [], null)->max('messages.sent_at'),
                default => null,
            };
            if ($date !== null) {
                $dates[] = Carbon::parse($date);
            }
        }

        return $this->minutes(collect($dates)->sortDesc()->first());
    }

    private function minutes(mixed $date): ?float
    {
        return $date ? max(0, Carbon::parse($date)->diffInMinutes(now())) : null;
    }
}
