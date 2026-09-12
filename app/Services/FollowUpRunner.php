<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

class FollowUpRunner
{
    public function run(): void
    {
        DB::table('follow_ups')->where('state', 'pending')->where('due_at', '<=', now())->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                DB::transaction(function () use ($row) {
                    $ticket = Ticket::whereKey($row->ticket_id)->lockForUpdate()->first();
                    $follow = DB::table('follow_ups')->where('id', $row->id)->lockForUpdate()->first();
                    if (! $ticket || $follow->state !== 'pending') {
                        return;
                    }
                    $reason = $ticket->merged_into_id ? 'Ticket merged' : (in_array($ticket->folder, ['spam', 'trash']) ? 'Ticket is in '.$ticket->folder : null);
                    if ($follow->cancel_on_reply && $ticket->messages()->where('kind', 'inbound')->where('id', '>', $follow->inbound_message_id)->exists()) {
                        $reason = 'Customer replied';
                    }
                    if ($reason) {
                        DB::table('follow_ups')->where('id', $row->id)->update(['state' => 'cancelled', 'result' => $reason, 'updated_at' => now()]);

                        return;
                    }
                    $message = app(WorkflowActions::class)->message($ticket, $follow->body, false, 'Timed follow-up', $follow->user_id);
                    if ($follow->status_after) {
                        $ticket->update(['status' => $follow->status_after, 'resolved_at' => in_array($follow->status_after, ['Solved', 'Closed']) ? now() : null]);
                    }
                    DB::table('follow_ups')->where('id', $row->id)->update(['state' => 'processed', 'message_id' => $message->id, 'result' => $message->delivery, 'updated_at' => now()]);
                    Activity::create(['ticket_id' => $ticket->id, 'description' => 'Timed follow-up processed for #'.$ticket->id.' ('.$message->delivery.').']);
                }, 5);
            }
        });
    }
}
