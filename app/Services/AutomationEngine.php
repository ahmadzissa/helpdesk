<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Automation;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

class AutomationEngine
{
    public function run(Ticket $ticket, string $trigger): void
    {
        DB::transaction(function () use ($ticket, $trigger) {
            $ticket = Ticket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            if ($ticket->merged_into_id || $ticket->folder !== 'inbox') {
                return;
            }
            if ($trigger !== 'time.elapsed') {
                $ticket->increment('event_version');
            }
            foreach (Automation::where('enabled', true)->where('trigger', $trigger)->orderBy('id')->get() as $rule) {
                $ticket->refresh();
                if ($ticket->folder !== 'inbox' || ! app(WorkflowConditions::class)->matches($ticket, $rule->conditions ?? [])) {
                    continue;
                }
                $runs = DB::table('automation_runs')->where('automation_id', $rule->id)->where('ticket_id', $ticket->id);
                if ((clone $runs)->count() >= $rule->max_runs) {
                    continue;
                }
                if ($rule->repeat_mode === 'interval' && (clone $runs)->where('created_at', '>', now()->subMinutes($rule->interval_minutes))->exists()) {
                    continue;
                }
                $runKey = match ($rule->repeat_mode) {
                    'event' => 'event:'.$ticket->event_version,
                    'interval' => 'interval:'.intdiv(now()->timestamp, max(1, $rule->interval_minutes) * 60),
                    default => 'once',
                };
                if (! DB::table('automation_runs')->insertOrIgnore(['automation_id' => $rule->id, 'ticket_id' => $ticket->id, 'run_key' => $runKey, 'created_at' => now()])) {
                    continue;
                }
                app(WorkflowActions::class)->apply($ticket, $rule->actions, $rule->name);
                Activity::create(['ticket_id' => $ticket->id, 'description' => 'Automation “'.$rule->name.'” ran on #'.$ticket->id]);
            }
        }, 5);
    }

    public static function expand(string $body, Ticket $ticket, string $agent = 'The team'): string
    {
        return strtr($body, ['{{name}}' => $ticket->requester_name ?: $ticket->requester_email, '{{email}}' => $ticket->requester_email, '{{ticket_id}}' => (string) $ticket->id, '{{subject}}' => $ticket->subject, '{{agent}}' => $agent]);
    }
}
