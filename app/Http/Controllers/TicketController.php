<?php

namespace App\Http\Controllers;

use App\Http\Resources\TicketResource;
use App\Jobs\SendTicketReply;
use App\Models\Activity;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\SavedView;
use App\Models\Ticket;
use App\Models\TicketDraft;
use App\Services\AutomationEngine;
use App\Services\EmailContent;
use App\Services\InlineImages;
use App\Services\MailSafety;
use App\Services\TicketCustomFields;
use App\Services\TicketDeletion;
use App\Services\TranslationPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TicketController extends Controller
{
    private function scope(Request $request): Builder
    {
        $query = Ticket::whereNull('merged_into_id');
        if ($request->filled('mailbox_id')) {
            $query->where('mailbox_id', $request->integer('mailbox_id'));
        }
        if ($request->filled('team_id')) {
            $query->whereHas('mailbox', fn ($mailbox) => $mailbox->where('team_id', $request->integer('team_id')));
        }

        return $query;
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate(['search' => 'nullable|string|max:255', 'page' => 'nullable|integer|min:1', 'sort' => 'nullable|in:newest,oldest', 'mailbox_id' => 'nullable|integer', 'team_id' => 'nullable|integer', 'counts_only' => 'sometimes|boolean', 'include_counts' => 'sometimes|boolean']);
        $summary = $request->boolean('include_counts', true) || $request->boolean('counts_only') ? $this->summary($request) : [];
        if ($request->boolean('counts_only')) {
            return response()->json($summary);
        }
        $query = $this->scope($request);
        $view = $request->input('view', 'all');
        if (in_array($view, ['archive', 'spam', 'trash'])) {
            $query->where('folder', $view);
        } else {
            $query->inInbox();
        }
        if (in_array($view, Ticket::STATUSES)) {
            $query->where('status', $view);
        }
        if ($view === 'mine') {
            $query->where('assignee_id', $request->user()->id);
        }
        if ($view === 'unassigned') {
            $query->whereNull('assignee_id');
        }
        if ($view === 'unread') {
            $query->where('unread', true);
        }
        if ($view === 'undelivered') {
            $query->whereHas('messages', fn ($q) => $q->whereIn('delivery', ['failed', 'held', 'suppressed', 'translation_pending']));
        }
        if (str_starts_with($view, 'saved:')) {
            $saved = SavedView::findOrFail(substr($view, 6));
            $query->matching($saved->filters ?? [], $saved->tag);
        }
        if ($request->filled('search')) {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], ltrim($request->string('search')->trim()->toString(), '#')).'%';
            $query->where(function ($q) use ($term) {
                $q->where('subject', 'like', $term)->orWhere('requester_email', 'like', $term)->orWhere('requester_name', 'like', $term)->orWhere('id', 'like', $term)->orWhere('tags', 'like', $term)->orWhereHas('messages', fn ($m) => $m->where('body', 'like', $term));
            });
        }
        $tickets = $query->with(['mailbox:id,name,email,color', 'assignee:id,name', 'team:id,name'])->orderBy('last_activity_at', $request->input('sort') === 'oldest' ? 'asc' : 'desc')->orderByDesc('id')->paginate(50);
        $this->loadLatestMessages($tickets->getCollection());

        return response()->json(['tickets' => TicketResource::collection($tickets)->resolve(), 'total' => $tickets->total(), 'current_page' => $tickets->currentPage(), 'last_page' => $tickets->lastPage(), ...$summary]);
    }

    private function summary(Request $request): array
    {
        $base = $this->scope($request)->inInbox();
        $aggregate = (clone $base)->toBase()->selectRaw('COUNT(*) AS total_count, SUM(CASE WHEN assignee_id = ? THEN 1 ELSE 0 END) AS mine_count, SUM(CASE WHEN assignee_id IS NULL THEN 1 ELSE 0 END) AS unassigned_count', [$request->user()->id]);
        foreach (Ticket::STATUSES as $index => $status) {
            $aggregate->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS status_'.$index, [$status]);
        }
        $totals = $aggregate->first();
        $counts = ['all' => (int) $totals->total_count, 'mine' => (int) $totals->mine_count, 'unassigned' => (int) $totals->unassigned_count];
        foreach (Ticket::STATUSES as $index => $status) {
            $counts[$status] = (int) $totals->{'status_'.$index};
        }
        $views = SavedView::all()->map(fn ($view) => [...$view->toArray(), 'count' => (clone $base)->matching($view->filters ?? [], $view->tag)->count()]);

        return ['counts' => $counts, 'views' => $views];
    }

    private function loadLatestMessages(Collection $tickets): void
    {
        if ($tickets->isEmpty()) {
            return;
        }
        $latestIds = Message::whereIn('ticket_id', $tickets->modelKeys())->selectRaw('MAX(id) AS id')->groupBy('ticket_id')->pluck('id');
        $messages = Message::whereKey($latestIds)->get(['id', 'ticket_id', 'kind', 'delivery', 'attempt_id'])->keyBy('ticket_id');
        $attempts = DB::table('mail_delivery_attempts')->whereIn('id', $messages->pluck('attempt_id')->filter())->get(['id', 'opened_at', 'delivered_at'])->keyBy('id');
        foreach ($tickets as $ticket) {
            $message = $messages->get($ticket->id);
            if ($message) {
                $message->setAttribute('opened_at', $attempts->get($message->attempt_id)?->opened_at);
                $message->setAttribute('delivered_at', $attempts->get($message->attempt_id)?->delivered_at);
            }
            $ticket->setRelation('latestMessage', $message);
        }
    }

    private function rules(): array
    {
        return [
            'subject' => 'sometimes|required|string|max:255', 'requester_name' => 'nullable|string|max:100',
            'requester_email' => 'sometimes|required|email|max:255', 'company' => 'nullable|string|max:100',
            'status' => ['sometimes', Rule::in(Ticket::STATUSES)], 'priority' => ['sometimes', Rule::in(Ticket::PRIORITIES)],
            'folder' => 'sometimes|in:inbox,archive,spam,trash', 'source' => 'sometimes|in:Email,Web,Webhook,Phone',
            'mailbox_id' => 'nullable|exists:mailboxes,id', 'team_id' => 'nullable|exists:teams,id', 'assignee_id' => 'nullable|exists:users,id',
            'tags' => 'sometimes|array|max:20', 'tags.*' => 'required|string|max:60|distinct',
            'cc' => 'sometimes|array|max:20', 'cc.*' => 'required|email|max:255|distinct',
            'custom_fields' => 'sometimes|array|max:20', 'custom_fields.*' => 'nullable|string|max:500',
            'unread' => 'sometimes|boolean',
        ];
    }

    public function store(Request $request, AutomationEngine $engine): JsonResponse
    {
        $data = $request->validate(array_merge($this->rules(), ['subject' => 'required|string|max:255', 'requester_email' => 'required|email|max:255', 'body' => 'required|string|max:50000']));
        $ticket = DB::transaction(function () use ($data, $request, $engine) {
            app(TicketCustomFields::class)->validateValues($data['custom_fields'] ?? []);
            $ticket = Ticket::create([...collect($data)->except('body')->all(), 'last_activity_at' => now(), 'tags' => $data['tags'] ?? []]);
            $ticket->messages()->create(['body' => $data['body'], 'kind' => 'inbound', 'author_name' => $ticket->requester_name, 'author_email' => $ticket->requester_email]);
            Activity::create(['ticket_id' => $ticket->id, 'user_id' => $request->user()->id, 'description' => 'Created ticket #'.$ticket->id]);
            $engine->run($ticket, 'ticket.created');

            return $ticket;
        });

        return (new TicketResource($ticket->fresh(['messages', 'mailbox', 'assignee', 'team'])))->response()->setStatusCode(201);
    }

    public function show(Request $request, Ticket $ticket): JsonResponse
    {
        $ticket->load(['messages', 'mailbox:id,name,email,color,sending_enabled', 'assignee:id,name', 'team:id,name']);
        $children = Ticket::where('merged_into_id', $ticket->id)->pluck('id')->all();
        if ($children !== []) {
            $ticket->setRelation('messages', Message::whereIn('ticket_id', [$ticket->id, ...$children])->orderBy('created_at')->orderBy('id')->get());
        }

        return response()->json(['ticket' => (new TicketResource($ticket))->resolve(),
            'draft' => TicketDraft::where('ticket_id', $ticket->id)->where('user_id', $request->user()->id)->first(),
            'related' => Ticket::where('requester_email', $ticket->requester_email)->where('id', '!=', $ticket->id)->latest()->limit(8)->get(['id', 'subject', 'status']),
            'activity' => Activity::where('ticket_id', $ticket->id)->latest()->limit(30)->get(),
        ]);
    }

    private function change(Ticket $ticket, array $data, Request $request, AutomationEngine $engine): void
    {
        $ticket = Ticket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
        $ticket->assertWritable();
        abort_if(array_key_exists('source', $data) && $data['source'] !== $ticket->source, 422, 'The original ticket source cannot be changed.');
        if (array_key_exists('custom_fields', $data)) {
            app(TicketCustomFields::class)->validateValues($data['custom_fields']);
            $data['custom_fields'] = array_replace($ticket->custom_fields ?? [], $data['custom_fields']);
        }
        if (isset($data['status'])) {
            $data['resolved_at'] = in_array($data['status'], ['Solved', 'Closed']) ? ($ticket->resolved_at ?? now()) : null;
        }
        $ticket->fill($data);
        if ($ticket->isDirty('mailbox_id')) {
            abort_unless($ticket->mailbox_id && Mailbox::whereKey($ticket->mailbox_id)->where('sending_enabled', true)->exists(), 422, 'Choose an email account with sending enabled.');
        }
        abort_if($ticket->isDirty(['requester_email', 'mailbox_id']) && Ticket::where('merged_into_id', $ticket->id)->exists(), 409, 'A merged conversation must keep its original requester and mailbox. Create a separate ticket for a different address.');
        $changed = array_keys($ticket->getDirty());
        if ($changed === []) {
            return;
        }
        $ticket->save();
        if (array_diff($changed, ['unread']) !== []) {
            Activity::create(['ticket_id' => $ticket->id, 'user_id' => $request->user()->id, 'description' => 'Updated '.implode(', ', array_diff($changed, ['resolved_at'])).' on #'.$ticket->id]);
            $engine->run($ticket, 'ticket.updated');
        }
    }

    public function update(Request $request, Ticket $ticket, AutomationEngine $engine): TicketResource
    {
        $data = $request->validate($this->rules());
        DB::transaction(fn () => $this->change($ticket, $data, $request, $engine));

        return new TicketResource($ticket->fresh(['mailbox', 'assignee', 'team']));
    }

    public function bulk(Request $request, AutomationEngine $engine): JsonResponse
    {
        $data = $request->validate(['ids' => 'required|array|min:1|max:100', 'ids.*' => 'required|integer|distinct|exists:tickets,id', 'changes' => 'required|array:status,priority,folder,assignee_id,team_id,unread', 'tag' => 'nullable|string|max:60',
            ...collect($this->rules())->only(['status', 'priority', 'folder', 'assignee_id', 'team_id', 'unread'])->mapWithKeys(fn ($value, $key) => ['changes.'.$key => $value])->all()]);
        DB::transaction(function () use ($data, $request, $engine) {
            foreach (Ticket::whereIn('id', $data['ids'])->get() as $ticket) {
                $changes = $data['changes'];
                if (! empty($data['tag'])) {
                    $changes['tags'] = array_values(array_unique([...($ticket->tags ?? []), $data['tag']]));
                }
                $this->change($ticket, $changes, $request, $engine);
            }
        });

        return response()->json(['updated' => count($data['ids'])]);
    }

    public function bulkDestroy(Request $request, TicketDeletion $deletion): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate(['ids' => 'required|array|min:1|max:1000', 'ids.*' => 'required|integer|distinct|exists:tickets,id', 'confirmed' => 'required|accepted']);
        $deleted = DB::transaction(function () use ($data, $request, $deletion): int {
            $selected = Ticket::whereKey($data['ids'])->orderBy('id')->lockForUpdate()->get();
            abort_unless($selected->count() === count($data['ids']), 409, 'The selected tickets changed. Refresh the list and try again.');
            foreach ($selected as $ticket) {
                $ticket->assertWritable();
            }
            $tickets = Ticket::where(fn ($query) => $query->whereIn('id', $data['ids'])->orWhereIn('merged_into_id', $data['ids']));
            $deleted = $deletion->delete($tickets);
            Activity::create(['user_id' => $request->user()->id, 'description' => 'Permanently deleted '.$deleted.' ticket(s) from '.count($data['ids']).' selected conversation(s).']);

            return $deleted;
        });

        return response()->json(['deleted' => $deleted]);
    }

    public function message(Request $request, Ticket $ticket): TicketResource
    {
        $ticket->assertWritable();
        if (is_string($request->input('translation'))) {
            $request->merge(['translation' => json_decode($request->input('translation'), true) ?? false]);
        }
        $data = $request->validate(['body' => 'required|string|max:50000', 'private' => 'boolean', 'status' => ['sometimes', Rule::in(Ticket::STATUSES)], 'attachments' => 'nullable|array|max:5', 'attachments.*' => 'file|max:10240', ...app(TranslationPolicy::class)->rules()]);
        $files = [];
        try {
            foreach ($request->file('attachments', []) as $file) {
                $files[] = ['path' => $file->store('ticket-attachments', 'local'), 'name' => $file->getClientOriginalName(), 'size' => $file->getSize()];
            }
            DB::transaction(function () use ($request, $ticket, $data, $files) {
                $ticket = Ticket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
                $ticket->assertWritable();
                $private = $request->boolean('private');
                $translation = $private ? [] : app(TranslationPolicy::class)->outgoing($ticket, $data['body'], $data['translation'] ?? null, (bool) ($data['send_original'] ?? false));
                $message = $ticket->messages()->create(['body' => $data['body'], 'kind' => $private ? 'note' : 'outbound', 'user_id' => $request->user()->id,
                    'author_name' => $request->user()->name, 'author_email' => $ticket->mailbox?->email, ...($private ? ['delivery' => null] : app(MailSafety::class)->prepare($ticket->mailbox)), 'attachments' => $files, ...$translation]);
                app(InlineImages::class)->bind($message, $ticket, $request->user()->id);
                $status = $data['status'] ?? $ticket->status;
                $ticket->update(['unread' => false, 'status' => $status, 'last_activity_at' => now(), 'resolved_at' => in_array($status, ['Solved', 'Closed']) ? ($ticket->resolved_at ?? now()) : null]);
                TicketDraft::where('ticket_id', $ticket->id)->where('user_id', $request->user()->id)->delete();
                Activity::create(['ticket_id' => $ticket->id, 'user_id' => $request->user()->id, 'description' => ($private ? 'Added private note to #' : 'Replied to #').$ticket->id]);
                if ($message->delivery === 'queued') {
                    SendTicketReply::dispatch($message)->afterCommit();
                }
                app(AutomationEngine::class)->run($ticket, $private ? 'ticket.updated' : 'message.sent');
            });
        } catch (\Throwable $exception) {
            foreach ($files as $file) {
                Storage::disk('local')->delete($file['path']);
            }
            throw $exception;
        }

        return new TicketResource($ticket->fresh(['messages', 'mailbox', 'assignee', 'team']));
    }

    public function draft(Request $request, Ticket $ticket): JsonResponse
    {
        $ticket->assertWritable();
        $data = $request->validate(['body' => 'nullable|string|max:50000', 'private' => 'required|boolean']);
        DB::transaction(function () use ($ticket, $request, $data) {
            $ticket = Ticket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $ticket->assertWritable();
            TicketDraft::updateOrCreate(['ticket_id' => $ticket->id, 'user_id' => $request->user()->id], $data);
        });

        return response()->json(['saved' => true]);
    }

    public function retry(Message $message): JsonResponse
    {
        $message->ticket->assertWritable();
        abort_unless($message->kind === 'outbound' && in_array($message->delivery, ['failed', 'held']), 422, 'Only failed or held outgoing messages can be released.');
        abort_unless($message->ticket->mailbox?->sending_enabled, 422, 'Connect and enable the outgoing mailbox before retrying.');
        DB::transaction(function () use ($message) {
            $locked = Message::whereKey($message->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($locked->delivery, ['failed', 'held']), 409, 'This message is already being processed.');
            abort_unless(app(TranslationPolicy::class)->ready($locked), 409, 'Translation needs review. Translate this unsent reply, or compose a new reply if a delivery was already attempted.');
            $delivery = app(MailSafety::class)->prepare($locked->ticket->mailbox);
            abort_if($delivery['delivery'] === 'held', 409, 'Sending is paused. An administrator must review and reactivate it first.');
            $locked->update($delivery);
            SendTicketReply::dispatch($locked)->afterCommit();
        });

        return response()->json(['message' => 'Delivery retry queued.'], 202);
    }

    public function inlineAttachment(Message $message, int $index): StreamedResponse
    {
        $file = ($message->attachments ?? [])[$index] ?? null;
        $disk = Storage::disk('local');
        abort_unless($file && $disk->exists($file['path']), 404);
        $mime = $disk->mimeType($file['path']);
        abort_unless(in_array($mime, EmailContent::IMAGE_TYPES, true), 404);

        return $disk->response($file['path'], 'email-image', ['Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600', 'Content-Security-Policy' => "default-src 'none'; sandbox"], 'inline');
    }

    public function attachment(Message $message, int $index): StreamedResponse
    {
        $file = ($message->attachments ?? [])[$index] ?? null;
        abort_unless($file && Storage::disk('local')->exists($file['path']), 404);

        return Storage::disk('local')->download($file['path'], $file['name'], ['X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'"]);
    }
}
