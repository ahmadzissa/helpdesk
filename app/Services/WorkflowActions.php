<?php

namespace App\Services;

use App\Jobs\SendTicketReply;
use App\Models\CannedReply;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class WorkflowActions
{
    public const TYPES = ['status', 'priority', 'folder', 'assignee_id', 'team_id', 'add_tag', 'remove_tag', 'reply_id', 'note', 'follow_up', 'send_message', 'send_follow_up'];

    public static function normalize(array $actions): array
    {
        if (array_is_list($actions)) {
            return $actions;
        }
        $result = [];
        foreach ($actions as $key => $value) {
            if ($value !== null && $value !== '') {
                $result[] = ['type' => $key === 'tag' ? 'add_tag' : $key, 'value' => $value];
            }
        }

        return $result;
    }

    public function apply(Ticket $ticket, array $actions, string $label, ?int $userId = null, ?int $automationId = null): void
    {
        $ticket->assertWritable();
        foreach (self::normalize($actions) as $action) {
            $type = $action['type'];
            $value = $action['value'] ?? null;
            if (in_array($type, ['status', 'priority', 'folder', 'assignee_id', 'team_id'])) {
                $changes = [$type => $value === '' ? null : $value];
                if ($type === 'status') {
                    $changes['resolved_at'] = in_array($value, ['Solved', 'Closed']) ? ($ticket->resolved_at ?? now()) : null;
                }
                $ticket->update($changes);
            } elseif (in_array($type, ['add_tag', 'remove_tag'])) {
                $tags = $ticket->tags ?? [];
                $tags = $type === 'add_tag' ? array_unique([...$tags, $value]) : array_filter($tags, fn ($tag) => mb_strtolower($tag) !== mb_strtolower($value));
                $ticket->update(['tags' => array_values($tags)]);
            } elseif ($type === 'reply_id' && ($reply = CannedReply::find($value))) {
                $this->message($ticket, $reply->body, false, $label, $userId);
            } elseif ($type === 'note') {
                $this->message($ticket, $value, true, $label, $userId);
            } elseif ($type === 'send_message') {
                $this->message($ticket, $value, false, $label, $userId);
            } elseif ($type === 'send_follow_up') {
                $message = $this->message($ticket, $value, false, $label, $userId);
                DB::table('follow_ups')->insert([
                    'ticket_id' => $ticket->id, 'user_id' => $userId, 'automation_id' => $automationId,
                    'body' => $message->body, 'due_at' => now(), 'cancel_on_reply' => false,
                    'inbound_message_id' => $ticket->messages()->where('kind', 'inbound')->max('id') ?? 0,
                    'state' => 'processed', 'message_id' => $message->id, 'result' => $message->delivery,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } elseif ($type === 'follow_up') {
                DB::table('follow_ups')->insert([
                    'ticket_id' => $ticket->id, 'user_id' => $userId, 'automation_id' => $automationId, 'body' => AutomationEngine::expand($action['body'], $ticket),
                    'due_at' => now()->addMinutes((int) $value), 'cancel_on_reply' => true,
                    'inbound_message_id' => $ticket->messages()->where('kind', 'inbound')->max('id') ?? 0,
                    'state' => 'pending', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function message(Ticket $ticket, string $body, bool $private, string $label, ?int $userId = null): Message
    {
        $message = $ticket->messages()->create([
            'body' => AutomationEngine::expand($body, $ticket, $userId ? (User::find($userId)?->name ?? 'The team') : 'The team'),
            'kind' => $private ? 'note' : 'outbound', 'user_id' => $userId,
            'author_name' => $userId ? (User::find($userId)?->name ?? 'The team') : 'Automated message',
            'author_email' => $ticket->mailbox?->email, 'rule_name' => $label,
            ...($private ? [] : app(MailSafety::class)->prepare($ticket->mailbox)),
        ]);
        $ticket->update(['last_activity_at' => now()]);
        app(TranslationPolicy::class)->holdIfNeeded($message);
        if ($message->delivery === 'queued') {
            SendTicketReply::dispatch($message)->afterCommit();
        }

        return $message;
    }
}
