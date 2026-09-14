<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Message;
use App\Models\Ticket;
use App\Services\AutomationEngine;
use App\Services\WorkflowActions;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TicketWorkflowController extends Controller
{
    public function history(Request $request, Ticket $ticket): JsonResponse
    {
        $data = $request->validate(['page' => 'sometimes|integer|min:1', 'folder' => 'sometimes|in:archive', 'q' => 'nullable|string|max:200', 'merge_candidates' => 'sometimes|boolean']);
        $history = Ticket::whereRaw('LOWER(requester_email) = ?', [mb_strtolower($ticket->requester_email)])->where('id', '!=', $ticket->id);
        $recent = (clone $history)->where('folder', 'inbox')->orderByDesc('last_activity_at')->orderByDesc('id')->limit(8)->get(['id', 'subject', 'status', 'merged_into_id']);
        $open = (clone $history)->whereNull('merged_into_id')->where('folder', 'inbox')->whereIn('status', ['Open', 'Pending', 'On hold'])->latest()->limit(100)->get(['id', 'subject', 'status', 'mailbox_id', 'created_at']);
        $tokens = $this->words($ticket->subject);
        $open = $open->map(function ($item) use ($tokens, $ticket) {
            $words = $this->words($item->subject);
            $score = count(array_intersect($tokens, $words)) / max(1, count(array_unique([...$tokens, ...$words])));

            return [...$item->toArray(), 'similar' => $score >= 0.4, 'merge_allowed' => $item->mailbox_id === $ticket->mailbox_id];
        })->sortByDesc('similar')->values();

        if (($data['folder'] ?? null) === 'archive') {
            $history->where('folder', 'archive');
        }
        if ($request->boolean('merge_candidates')) {
            $history->whereNull('merged_into_id')->where('mailbox_id', $ticket->mailbox_id)->whereNotIn('folder', ['spam', 'trash']);
        }
        if ($search = trim($data['q'] ?? '')) {
            $history->whereRaw('LOWER(subject) LIKE ?', ['%'.mb_strtolower($search).'%']);
        }

        return response()->json(['open' => $open, 'recent' => $recent, 'history' => $history->latest()->paginate(20, ['id', 'subject', 'status', 'folder', 'mailbox_id', 'merged_into_id', 'created_at', 'last_activity_at'])]);
    }

    private function words(string $subject): array
    {
        $subject = preg_replace('/\[#\d+\]|^(re|fw|fwd):\s*/i', '', $subject);

        return array_values(array_unique(array_filter(preg_split('/[^\pL\pN]+/u', mb_strtolower($subject)), fn ($word) => mb_strlen($word) > 2)));
    }

    public function merge(Request $request, Ticket $ticket): JsonResponse
    {
        $data = $request->validate(['ticket_ids' => 'required|array|min:1|max:20', 'ticket_ids.*' => 'required|integer|distinct|exists:tickets,id']);
        DB::transaction(function () use ($request, $ticket, $data) {
            $locked = Ticket::whereIn('id', [$ticket->id, ...$data['ticket_ids']])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $parent = $locked[$ticket->id];
            $parent->assertWritable();
            abort_if(in_array($parent->folder, ['spam', 'trash']), 422, 'Restore the main ticket before merging.');
            foreach ($data['ticket_ids'] as $id) {
                $child = $locked[$id];
                $child->assertWritable();
                abort_if(in_array($child->folder, ['spam', 'trash']), 422, 'Restore selected tickets before merging.');
                abort_unless($child->id !== $parent->id && mb_strtolower($child->requester_email) === mb_strtolower($parent->requester_email) && $child->mailbox_id === $parent->mailbox_id, 422, 'Merge tickets from the same requester and mailbox only.');
            }
            foreach ($data['ticket_ids'] as $id) {
                $child = $locked[$id];
                $descendants = Ticket::where('merged_into_id', $id)->pluck('id')->all();
                $all = [$id, ...$descendants];
                Ticket::whereIn('id', $all)->update(['merged_into_id' => $parent->id, 'status' => 'Closed', 'resolved_at' => now(), 'unread' => false]);
                Message::whereIn('ticket_id', $all)->where('delivery', 'queued')->update(['delivery' => 'held', 'delivery_error' => 'Ticket merged. Review and write any further reply in the main conversation.']);
                DB::table('follow_ups')->whereIn('ticket_id', $all)->where('state', 'pending')->update(['state' => 'cancelled', 'result' => 'Ticket merged', 'updated_at' => now()]);
                $parent->update(['tags' => array_values(array_unique([...($parent->tags ?? []), ...($child->tags ?? [])]))]);
                Activity::create(['ticket_id' => $parent->id, 'user_id' => $request->user()->id, 'description' => 'Merged #'.$id.' into this ticket. Earlier messages remain in #'.$id.'.']);
                Activity::create(['ticket_id' => $id, 'user_id' => $request->user()->id, 'description' => 'Merged into #'.$parent->id.'. Future customer replies go to the main ticket.']);
            }
            $parent->update(['last_activity_at' => now()]);
        }, 5);

        return response()->json(['merged_into_id' => $ticket->id]);
    }

    public function applyMacro(Request $request, Ticket $ticket, int $macro): JsonResponse
    {
        $data = $request->validate(['request_id' => 'required|uuid']);
        DB::transaction(function () use ($request, $ticket, $macro, $data) {
            $ticket = Ticket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $ticket->assertWritable();
            $definition = DB::table('macros')->where('id', $macro)->where('enabled', true)->first();
            abort_unless($definition, 404, 'Macro is unavailable.');
            $existing = DB::table('macro_runs')->where('user_id', $request->user()->id)->where('request_id', $data['request_id'])->first();
            if ($existing) {
                abort_unless($existing->ticket_id === $ticket->id && $existing->macro_id === $macro, 409, 'This request ID was already used.');

                return;
            }
            DB::table('macro_runs')->insert(['macro_id' => $macro, 'ticket_id' => $ticket->id, 'user_id' => $request->user()->id, 'request_id' => $data['request_id'], 'created_at' => now()]);
            app(WorkflowActions::class)->apply($ticket, json_decode($definition->actions, true), 'Macro: '.$definition->name, $request->user()->id);
            Activity::create(['ticket_id' => $ticket->id, 'user_id' => $request->user()->id, 'description' => 'Applied macro “'.$definition->name.'” to #'.$ticket->id]);
            app(AutomationEngine::class)->run($ticket, 'ticket.updated');
        }, 5);

        return response()->json(['applied' => true]);
    }

    public function followUps(Ticket $ticket): JsonResponse
    {
        return response()->json(DB::table('follow_ups')->where('ticket_id', $ticket->id)->orderByDesc('id')->limit(100)->get());
    }

    public function schedule(Request $request, Ticket $ticket): JsonResponse
    {
        $data = $request->validate(['body' => 'required|string|max:20000', 'due_at' => 'required|date|after:now|before:'.now()->addYear()->toIso8601String(), 'cancel_on_reply' => 'required|boolean', 'status_after' => ['nullable', Rule::in(Ticket::STATUSES)]]);
        $id = DB::transaction(function () use ($request, $ticket, $data) {
            $ticket = Ticket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $ticket->assertWritable();
            abort_if(in_array($ticket->folder, ['spam', 'trash']), 422, 'Restore this ticket before scheduling a follow-up.');
            $id = DB::table('follow_ups')->insertGetId([...$data, 'due_at' => Carbon::parse($data['due_at'])->utc(), 'ticket_id' => $ticket->id, 'user_id' => $request->user()->id, 'inbound_message_id' => $ticket->messages()->where('kind', 'inbound')->max('id') ?? 0, 'state' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            Activity::create(['ticket_id' => $ticket->id, 'user_id' => $request->user()->id, 'description' => 'Scheduled follow-up #'.$id.' for #'.$ticket->id]);

            return $id;
        });

        return response()->json(DB::table('follow_ups')->find($id), 201);
    }

    public function cancel(Request $request, Ticket $ticket, int $followUp): JsonResponse
    {
        DB::transaction(function () use ($ticket, $followUp, $request) {
            Ticket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $updated = DB::table('follow_ups')->where('id', $followUp)->where('ticket_id', $ticket->id)->where('state', 'pending')->update(['state' => 'cancelled', 'result' => 'Cancelled by agent', 'updated_at' => now()]);
            abort_unless($updated, 409, 'This follow-up has already been processed or cancelled.');
            Activity::create(['ticket_id' => $ticket->id, 'user_id' => $request->user()->id, 'description' => 'Cancelled follow-up #'.$followUp]);
        });

        return response()->json(['cancelled' => true]);
    }

    public function upload(Request $request, Ticket $ticket): JsonResponse
    {
        $ticket->assertWritable();
        $request->validate(['image' => 'required|file|image|mimes:jpg,jpeg,png,gif,webp|max:5120|dimensions:max_width=8000,max_height=8000']);
        $file = $request->file('image');
        $id = (string) Str::uuid();
        $path = $file->store('inline-images', 'local');
        DB::table('inline_images')->insert(['id' => $id, 'ticket_id' => $ticket->id, 'user_id' => $request->user()->id, 'path' => $path, 'name' => mb_substr($file->getClientOriginalName(), 0, 200), 'mime' => $file->getMimeType(), 'created_at' => now()]);

        return response()->json(['id' => $id, 'url' => '/api/v1/inline-images/'.$id, 'markdown' => '![Image](/api/v1/inline-images/'.$id.')'], 201);
    }

    public function image(Request $request, string $id): StreamedResponse
    {
        $image = DB::table('inline_images')->where('id', $id)->first();
        abort_unless($image && ($image->message_id || $image->user_id === $request->user()->id), 404);
        abort_unless(Storage::disk('local')->exists($image->path), 404);

        return Storage::disk('local')->response($image->path, $image->name, ['Content-Type' => $image->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "default-src 'none'"]);
    }

    public function delivery(Message $message): JsonResponse
    {
        $attempts = DB::table('mail_delivery_attempts')->where('message_id', $message->id)->orderByDesc('created_at')->get();

        return response()->json(['delivery' => $message->delivery, 'error' => $message->delivery_error, 'attempts' => $attempts->map(fn ($attempt) => [...(array) $attempt, 'recipients' => json_decode($attempt->recipients ?? 'null', true) ?? [$attempt->recipient], 'events' => DB::table('delivery_events')->where('attempt_id', $attempt->id)->orderBy('occurred_at')->get()])]);
    }
}
