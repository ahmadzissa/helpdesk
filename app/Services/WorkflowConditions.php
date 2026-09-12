<?php

namespace App\Services;

use App\Models\Ticket;
use Carbon\Carbon;

class WorkflowConditions
{
    public const FIELDS = ['status', 'priority', 'folder', 'source', 'assignee_id', 'team_id', 'mailbox_id', 'subject', 'requester_email', 'requester_domain', 'company', 'tag', 'body', 'sender_trust', 'age_minutes', 'idle_minutes', 'since_customer_minutes', 'since_reply_minutes'];

    public const OPERATORS = ['eq', 'neq', 'contains', 'not_contains', 'starts_with', 'ends_with', 'empty', 'not_empty', 'gte', 'lte'];

    public function matches(Ticket $ticket, array $conditions): bool
    {
        if (! array_key_exists('all', $conditions) && ! array_key_exists('any', $conditions)) {
            $subject = $conditions['subject_contains'] ?? '';
            unset($conditions['subject_contains']);

            return (! $subject || str_contains(mb_strtolower($ticket->subject), mb_strtolower($subject)))
                && Ticket::whereKey($ticket->id)->matching($conditions)->exists();
        }
        $all = $conditions['all'] ?? [];
        $any = $conditions['any'] ?? [];

        return collect($all)->every(fn ($condition) => $this->test($ticket, $condition))
            && ($any === [] || collect($any)->contains(fn ($condition) => $this->test($ticket, $condition)));
    }

    private function test(Ticket $ticket, array $condition): bool
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
            default => $ticket->{$field},
        };
        $op = $condition['operator'];
        $wanted = mb_strtolower((string) ($condition['value'] ?? ''));
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
        $actual = mb_strtolower((string) $value);

        return match ($op) {
            'eq' => $actual === $wanted, 'neq' => $actual !== $wanted,
            'contains' => str_contains($actual, $wanted), 'not_contains' => ! str_contains($actual, $wanted),
            'starts_with' => str_starts_with($actual, $wanted), 'ends_with' => str_ends_with($actual, $wanted),
            'gte' => is_numeric($value) && is_numeric($wanted) && $value >= $wanted,
            'lte' => is_numeric($value) && is_numeric($wanted) && $value <= $wanted,
            default => false,
        };
    }

    private function minutes(mixed $date): ?float
    {
        return $date ? max(0, Carbon::parse($date)->diffInMinutes(now())) : null;
    }
}
