<?php

namespace App\Http\Controllers;

use App\Jobs\SyncMailbox;
use App\Models\Activity;
use App\Models\Automation;
use App\Models\CannedReply;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\SavedView;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkspaceSetting;
use App\Services\CannedImages;
use App\Services\InlineImages;
use App\Services\MailboxConnections;
use App\Services\MailSafety;
use App\Services\TicketDeletion;
use App\Services\TranslationPolicy;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class WorkspaceController extends Controller
{
    public function bootstrap(Request $request): JsonResponse
    {
        $settings = WorkspaceSetting::where('key', '!=', 'translation_key')->get()->pluck('value', 'key');
        $settings['general'] = array_replace(['show_email_images' => true], $settings['general'] ?? []);

        return response()->json([
            'user' => $request->user(), 'agents' => User::orderBy('name')->get(['id', 'name', 'email', 'role']),
            'mailboxes' => Mailbox::with('team')->get()->map(fn ($mailbox) => $request->user()->role === 'admin' ? [...$mailbox->toArray(), 'has_smtp_password' => (bool) $mailbox->smtp_password, 'has_imap_password' => (bool) $mailbox->imap_password] : $mailbox->only(['id', 'name', 'email', 'color', 'team_id', 'sending_enabled'])),
            'teams' => Team::all(), 'views' => SavedView::all(), 'replies' => CannedReply::all(),
            'automations' => Automation::all(), 'settings' => $settings,
            'translation' => app(TranslationPolicy::class)->settings(),
            'statuses' => Ticket::STATUSES, 'priorities' => Ticket::PRIORITIES,
            'sending_safety' => app(MailSafety::class)->status(),
            'registration_enabled' => WorkspaceSetting::registrationEnabled(),
            'local_mail_polling' => app()->environment('local'),
        ]);
    }

    private function model(string $type): string
    {
        return match ($type) {
            'views' => SavedView::class, 'replies' => CannedReply::class, 'mailboxes' => Mailbox::class,
            'teams' => Team::class, 'automations' => Automation::class, 'agents' => User::class,
            default => abort(404),
        };
    }

    private function filterRules(string $prefix): array
    {
        return [
            $prefix => 'sometimes|array:status,priority,assignee_id,team_id,source',
            $prefix.'.status' => ['nullable', Rule::in(Ticket::STATUSES)],
            $prefix.'.priority' => ['nullable', Rule::in(Ticket::PRIORITIES)],
            $prefix.'.assignee_id' => ['nullable', function ($attribute, $value, $fail) {
                if ($value !== 'unassigned' && ! User::whereKey($value)->exists()) {
                    $fail('Select a valid agent.');
                }
            }],
            $prefix.'.team_id' => 'nullable|exists:teams,id',
            $prefix.'.source' => 'nullable|in:Email,Web,Webhook,Phone',
        ];
    }

    public function save(Request $request, string $type, ?int $id = null): JsonResponse
    {
        if ($type === 'automations') {
            return app(WorkflowController::class)->save($request, 'rules', $id);
        }
        if (in_array($type, ['mailboxes', 'teams', 'automations', 'agents'])) {
            abort_unless($request->user()->role === 'admin', 403);
        }
        $class = $this->model($type);
        $model = $id ? $class::findOrFail($id) : new $class;
        $previousImages = $type === 'replies' ? app(CannedImages::class)->ids($model->body ?? '') : [];
        if ($type === 'replies' && is_string($request->input('shortcut'))) {
            $request->merge(['shortcut' => implode(', ', CannedReply::shortcuts($request->input('shortcut')))]);
        }
        $rules = match ($type) {
            'views' => ['name' => 'required|string|max:80', 'tag' => 'nullable|string|max:60', ...$this->filterRules('filters')],
            'replies' => ['title' => 'required|string|max:120', 'shortcut' => ['required', 'string', 'max:255', function (string $attribute, mixed $value, \Closure $fail) use ($id): void {
                if (! is_string($value)) {
                    return;
                }
                $shortcuts = CannedReply::shortcuts($value);
                foreach ($shortcuts as $shortcut) {
                    if (! preg_match('/^#[\pL\pN_-]+(?: [\pL\pN_-]+)*$/u', $shortcut) || mb_strlen($shortcut) > 40) {
                        $fail('Use comma-separated shortcuts with letters, numbers, spaces, hyphens or underscores (up to 40 characters each).');

                        return;
                    }
                }
                $normalized = array_map('mb_strtolower', $shortcuts);
                if (count(array_unique($normalized)) !== count($normalized)) {
                    $fail('Each shortcut must be different.');

                    return;
                }
                foreach (CannedReply::when($id, fn ($query) => $query->whereKeyNot($id))->pluck('shortcut') as $existing) {
                    if (array_intersect($normalized, array_map('mb_strtolower', CannedReply::shortcuts($existing)))) {
                        $fail('One of these shortcuts is already used by another canned response.');

                        return;
                    }
                }
            }], 'category' => 'required|string|max:60', 'body' => 'required|string|max:20000'],
            'teams' => ['name' => ['required', 'string', 'max:80', Rule::unique('teams')->ignore($id)], 'description' => 'nullable|string|max:255'],
            'agents' => ['name' => 'required|string|max:100', 'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($id)], 'role' => 'required|in:admin,agent', 'password' => [$id ? 'sometimes' : 'required', 'required', Password::min(12)]],
            'mailboxes' => [
                'connection_token' => 'nullable|string|size:64',
                'name' => 'required|string|max:100', 'email' => ['required', 'email', 'max:255', Rule::unique('mailboxes')->ignore($id)],
                'team_id' => 'nullable|exists:teams,id', 'color' => ['required', 'regex:/^#[a-fA-F0-9]{6}$/'],
                'smtp_host' => ['nullable', 'required_if:sending_enabled,true', 'regex:/^[a-zA-Z0-9.-]+$/', 'max:255'],
                'smtp_port' => 'required|integer|between:1,65535', 'smtp_encryption' => 'required|in:tls,ssl',
                'smtp_username' => 'nullable|string|max:255', 'smtp_password' => 'nullable|string|max:1000',
                'imap_host' => ['nullable', 'required_if:incoming_enabled,true', 'regex:/^[a-zA-Z0-9.-]+$/', 'max:255'], 'imap_port' => 'required|integer|between:1,65535',
                'imap_username' => 'nullable|required_if:incoming_enabled,true|string|max:255', 'imap_password' => 'nullable|string|max:1000',
                'incoming_enabled' => 'sometimes|boolean', 'imap_encryption' => 'sometimes|in:ssl,tls',
                'sending_enabled' => 'required|boolean',
            ],
            'automations' => [
                'name' => 'required|string|max:120', 'enabled' => 'required|boolean', 'trigger' => 'required|in:ticket.created,ticket.updated',
                'conditions' => 'present|array:status,priority,assignee_id,team_id,source,subject_contains',
                ...collect($this->filterRules('conditions'))->except('conditions')->all(),
                'conditions.subject_contains' => 'nullable|string|max:100',
                'actions' => 'required|array:status,priority,assignee_id,team_id,tag,reply_id',
                'actions.status' => ['nullable', Rule::in(Ticket::STATUSES)], 'actions.priority' => ['nullable', Rule::in(Ticket::PRIORITIES)],
                'actions.assignee_id' => 'nullable|exists:users,id', 'actions.team_id' => 'nullable|exists:teams,id',
                'actions.tag' => 'nullable|string|max:60', 'actions.reply_id' => 'nullable|exists:canned_replies,id',
            ],
        };
        $data = $request->validate($rules);
        if ($type === 'replies') {
            abort_if(app(InlineImages::class)->ids($data['body']) !== [], 422, 'Upload images in this canned response so they can be reused across tickets.');
            app(CannedImages::class)->images($data['body']);
        }
        if ($type === 'automations' && ! array_filter($data['actions'], fn ($v) => $v !== null && $v !== '')) {
            return response()->json(['message' => 'Choose at least one action.'], 422);
        }
        if ($type === 'mailboxes') {
            unset($data['connection_token']);
            if (($data['incoming_enabled'] ?? false) && ! filled($data['imap_password'] ?? $model->imap_password)) {
                return response()->json(['message' => 'Enter the IMAP password before enabling incoming mail.'], 422);
            }
            foreach (['smtp_password', 'imap_password'] as $field) {
                if (($data[$field] ?? '') === '') {
                    unset($data[$field]);
                }
            }
            $connections = app(MailboxConnections::class);
            $original = clone $model;
            $model->fill($data);
            if (! $model->exists || $connections->settings($model) !== $connections->settings($original)
                || ($model->sending_enabled && ! $original->sending_enabled) || ($model->incoming_enabled && ! $original->incoming_enabled)) {
                $connections->assertVerified($model, $request->user()->id, $request->input('connection_token'));
            }
        }
        if ($type === 'agents' && $id === $request->user()->id && $data['role'] !== 'admin') {
            abort(422, 'You cannot remove your own administrator role.');
        }
        if ($type === 'agents' && ! $id) {
            Cache::lock('workspace-access', 10)->block(3, function () use ($model, $data) {
                abort_unless(WorkspaceSetting::registrationEnabled(), 403, 'New account registration is disabled. An administrator can enable additional accounts in Settings → General.');
                $model->fill($data)->save();
            });
        } elseif ($type === 'mailboxes') {
            try {
                Cache::lock('imap-import-'.($model->id ?? 'new'), 950)->block(3, function () use ($model, $original, $data): void {
                    if ($model->exists && ($model->imap_host !== $original->imap_host || $model->imap_username !== $original->imap_username)) {
                        $model->forceFill(['import_started_at' => now(), 'imap_uidvalidity' => null, 'imap_last_uid' => 0, 'last_synced_at' => null]);
                    }
                    $model->fill($data)->save();
                });
            } catch (LockTimeoutException) {
                return response()->json(['message' => 'An email sync is finishing. Try saving the account again in a moment.'], 409);
            }
        } else {
            $model->fill($data)->save();
        }
        if ($type === 'mailboxes') {
            $connections->forget($original, $request->user()->id);
        }
        if ($type === 'replies') {
            foreach (array_diff($previousImages, app(CannedImages::class)->ids($model->body)) as $imageId) {
                app(CannedImages::class)->deleteUnused($imageId);
            }
        }
        Activity::create(['user_id' => $request->user()->id, 'description' => ($id ? 'Updated ' : 'Created ').$type.': '.($data['name'] ?? $data['title'])]);

        return response()->json($model, $id ? 200 : 201);
    }

    public function destroy(Request $request, string $type, int $id): JsonResponse
    {
        if (in_array($type, ['mailboxes', 'teams', 'automations', 'agents'])) {
            abort_unless($request->user()->role === 'admin', 403);
        }
        abort_if($type === 'agents', 422, 'Agent accounts cannot be deleted here.');
        $model = $this->model($type)::findOrFail($id);
        $deletedTickets = 0;
        if ($type === 'mailboxes') {
            $data = $request->validate(['delete_tickets' => 'sometimes|required|boolean']);
            $deleteTickets = (bool) ($data['delete_tickets'] ?? false);
            try {
                Cache::lock('imap-import-'.$id, 950)->block(3, function () use ($model, $id, $deleteTickets, &$deletedTickets): void {
                    DB::transaction(function () use ($model, $id, $deleteTickets, &$deletedTickets): void {
                        DB::table('api_keys')->where('mailbox_id', $id)->update(['mailbox_id' => null, 'revoked_at' => now(), 'updated_at' => now()]);
                        if ($deleteTickets) {
                            $deletedTickets = app(TicketDeletion::class)->delete(Ticket::where('mailbox_id', $id));
                        }
                        $model->delete();
                    });
                });
            } catch (LockTimeoutException) {
                return response()->json(['message' => 'An email sync is finishing. Try removing the account again in a moment.'], 409);
            }
        } else {
            $model->delete();
        }
        if ($type === 'replies') {
            foreach (app(CannedImages::class)->ids($model->body) as $imageId) {
                app(CannedImages::class)->deleteUnused($imageId);
            }
        }
        Activity::create(['user_id' => $request->user()->id, 'description' => 'Removed '.$type.' #'.$id.($deletedTickets ? ' and permanently deleted '.$deletedTickets.' ticket(s).' : '')]);

        return response()->json(['deleted' => true, 'deleted_tickets' => $deletedTickets]);
    }

    public function sync(Request $request, Mailbox $mailbox): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        abort_unless($mailbox->incoming_enabled, 422, 'Enable incoming mail and save the connection settings first.');
        if (app()->environment('local')) {
            set_time_limit(930);
            try {
                SyncMailbox::dispatchSync($mailbox->id);
            } catch (\Throwable $exception) {
                report($exception);
                abort(422, 'Incoming mail sync failed. Check the account connection settings.');
            }

            return response()->json(['message' => 'Incoming mail checked. New conversations are ready.']);
        }
        SyncMailbox::dispatch($mailbox->id);

        return response()->json(['message' => 'Sync queued. The queue worker will import new messages.'], 202);
    }

    public function settings(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate(['name' => 'required|string|max:100', 'timezone' => 'required|timezone', 'registration_enabled' => 'sometimes|boolean', 'show_email_images' => 'sometimes|boolean']);
        foreach (['registration_enabled', 'show_email_images'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $request->boolean($field);
            }
        }
        $data = Cache::lock('workspace-access', 10)->block(3, function () use ($request, $data) {
            return DB::transaction(function () use ($request, $data) {
                $wasEnabled = WorkspaceSetting::registrationEnabled();
                $settings = array_replace(['registration_enabled' => false, 'show_email_images' => true], WorkspaceSetting::find('general')?->value ?? [], $data);
                WorkspaceSetting::updateOrCreate(['key' => 'general'], ['value' => $settings]);
                if ($wasEnabled !== $settings['registration_enabled']) {
                    Activity::create(['user_id' => $request->user()->id, 'description' => 'New account registration '.($settings['registration_enabled'] ? 'enabled' : 'disabled').'.']);
                }

                return $settings;
            });
        });

        return response()->json($data);
    }

    public function reports(Request $request): JsonResponse
    {
        $request->validate(['days' => 'nullable|integer|in:7,30,90']);
        $days = $request->integer('days', 30);
        $start = now()->subDays($days - 1)->startOfDay();
        $tickets = Ticket::inInbox()->where('created_at', '>=', $start)->get();
        $resolved = $tickets->filter(fn ($t) => $t->resolved_at !== null);
        $trend = collect(range($days - 1, 0))->map(function ($offset) use ($tickets) {
            $day = now()->subDays($offset)->toDateString();

            return ['date' => $day, 'created' => $tickets->filter(fn ($t) => $t->created_at->toDateString() === $day)->count(), 'resolved' => Ticket::inInbox()->whereDate('resolved_at', $day)->count()];
        });
        $responseTimes = [];
        foreach ($tickets->load('messages') as $ticket) {
            $first = $ticket->messages->first(fn ($m) => $m->kind === 'outbound' && ! $m->rule_name);
            if ($first) {
                $responseTimes[] = max(0, $ticket->created_at->diffInMinutes($first->created_at));
            }
        }

        return response()->json([
            'total' => $tickets->count(), 'resolved' => $resolved->count(),
            'resolution_rate' => $tickets->count() ? round($resolved->count() / $tickets->count() * 100) : 0,
            'response_minutes' => count($responseTimes) ? round(array_sum($responseTimes) / count($responseTimes)) : null,
            'trend' => $trend, 'statuses' => $tickets->countBy('status'),
            'agents' => User::get(['id', 'name'])->map(fn ($user) => ['name' => $user->name, 'assigned' => $tickets->where('assignee_id', $user->id)->count(), 'resolved' => $resolved->where('assignee_id', $user->id)->count()]),
            'delivery' => Message::where('kind', 'outbound')->whereHas('ticket', fn ($ticket) => $ticket->inInbox())->where('created_at', '>=', $start)->select('delivery', DB::raw('count(*) as total'))->groupBy('delivery')->get(),
        ]);
    }

    public function activity(): JsonResponse
    {
        return response()->json(Activity::latest('id')->paginate(50));
    }
}
