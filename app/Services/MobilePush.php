<?php

namespace App\Services;

use App\Models\LiveChatAgent;
use App\Models\LiveChatThread;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use stdClass;

class MobilePush
{
    public const SOUNDS = [
        'new_ticket' => ['new-ticket-v1', 'new_ticket.wav', "You've got a new ticket."],
        'new_reply' => ['new-reply-v1', 'new_reply.wav', "You've got a new reply."],
        'new_chat' => ['new-chat-v1', 'new_chat.wav', "You've got a new chat."],
        'idle_warning' => ['availability-v1', 'idle_warning.wav', "Are you still there? You're about to go offline."],
    ];

    public function isChat(): bool
    {
        return config('mobile.source') === 'chat';
    }

    public function principal(Request $request): mixed
    {
        return $this->isChat() ? app(LiveChat::class)->currentAgent($request) : $request->user();
    }

    public function allowed(int $id): bool
    {
        return in_array($id, array_map('intval', config('mobile.owner_ids', [])), true);
    }

    public function devices(): Builder
    {
        return DB::table('mobile_devices')->whereNull('revoked_at');
    }

    public function register(Request $request, mixed $principal, array $data): stdClass
    {
        return DB::transaction(function () use ($request, $principal, $data): stdClass {
            DB::table('mobile_devices')->insertOrIgnore([
                'id' => $data['installation_id'], 'principal_id' => $principal->id,
                'push_token' => $data['push_token'], 'session_hash' => hash('sha256', $request->session()->getId()),
                'binding_id' => (string) Str::uuid(), 'activity_version' => (string) Str::uuid(),
                'auth_version' => $this->isChat() ? $principal->auth_version : null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $device = DB::table('mobile_devices')->where('id', $data['installation_id'])->lockForUpdate()->firstOrFail();
            $changed = $device->revoked_at || (int) $device->principal_id !== (int) $principal->id || $device->push_token !== $data['push_token']
                || ($this->isChat() && (int) $device->auth_version !== (int) $principal->auth_version);
            $values = ['principal_id' => $principal->id, 'push_token' => $data['push_token'],
                'session_hash' => hash('sha256', $request->session()->getId()), 'revoked_at' => null,
                'auth_version' => $this->isChat() ? $principal->auth_version : null, 'updated_at' => now()];
            if ($changed) {
                $values += ['binding_id' => (string) Str::uuid(), 'active_until' => null, 'last_activity_at' => null, 'activity_version' => (string) Str::uuid()];
            }
            DB::table('mobile_devices')->where('push_token', $data['push_token'])->where('id', '!=', $device->id)
                ->update(['revoked_at' => now(), 'active_until' => null]);
            DB::table('mobile_devices')->where('id', $device->id)->update($values);

            return DB::table('mobile_devices')->where('id', $device->id)->firstOrFail();
        });
    }

    public function revokeSession(Request $request): void
    {
        if (! config('mobile.enabled')) {
            return;
        }
        $this->devices()->where('session_hash', hash('sha256', $request->session()->getId()))
            ->update(['revoked_at' => now(), 'active_until' => null, 'updated_at' => now()]);
    }

    public function presence(stdClass $device): array
    {
        $expires = $device->active_until ? max(0, now()->diffInSeconds(Carbon::parse($device->active_until), false)) : 0;
        $warning = $device->last_activity_at ? max(0, now()->diffInSeconds(Carbon::parse($device->last_activity_at)->addMinutes(5), false)) : 0;

        return ['online' => $expires > 0, 'expires_in' => (int) ceil($expires), 'warn_in' => (int) ceil($warning),
            'other_device_online' => $this->isChat() && $this->otherDeviceOnline($device)];
    }

    public function otherDeviceOnline(stdClass $device): bool
    {
        return DB::table('live_chat_agent_sessions')->where('agent_id', $device->principal_id)->where('online', true)->where('heartbeat_at', '>', now()->subMinutes(5))->exists()
            || $this->devices()->where('principal_id', $device->principal_id)->where('id', '!=', $device->id)->where('active_until', '>', now())->exists();
    }

    public function activity(Request $request, mixed $principal, string $id, string $action): array
    {
        return DB::transaction(function () use ($request, $principal, $id, $action): array {
            $device = $this->devices()->where('id', $id)->where('principal_id', $principal->id)
                ->where('session_hash', hash('sha256', $request->session()->getId()))->lockForUpdate()->firstOrFail();
            $online = $action === 'online' || ($action === 'activity' && $device->active_until && Carbon::parse($device->active_until)->isFuture());
            DB::table('mobile_devices')->where('id', $id)->update([
                'last_activity_at' => $online ? now() : null, 'active_until' => $online ? now()->addMinutes(10) : null,
                'activity_version' => (string) Str::uuid(), 'updated_at' => now(),
            ]);

            return $this->presence(DB::table('mobile_devices')->where('id', $id)->firstOrFail());
        });
    }

    /** The durable outbox is written in the event's transaction; workers only see committed rows. */
    public function enqueue(string $eventId, string $type, string $recordId, ?Builder $recipients = null, ?string $condition = null, ?string $occurredAt = null): void
    {
        if (! config('mobile.enabled') || ! isset(self::SOUNDS[$type])) {
            return;
        }
        $recipients ??= $this->devices();
        if ($occurredAt) {
            $recipients->where('created_at', '<=', Carbon::parse($occurredAt));
        }
        foreach ($recipients->get() as $device) {
            if (! $this->allowed((int) $device->principal_id)) {
                continue;
            }
            DB::table('mobile_push_deliveries')->insertOrIgnore([
                'id' => hash('sha256', $eventId.'|'.$device->id), 'event_id' => $eventId, 'device_id' => $device->id,
                'binding_id' => $device->binding_id, 'type' => $type, 'record_id' => $recordId, 'condition' => $condition,
                'status' => 'pending', 'attempts' => 0, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function enqueueIdleWarnings(): void
    {
        if (! $this->isChat()) {
            return;
        }
        foreach ($this->devices()->where('active_until', '>', now())->where('last_activity_at', '<=', now()->subMinutes(5))->get() as $device) {
            if ($this->otherDeviceOnline($device)) {
                continue;
            }
            $this->enqueue('idle:'.$device->activity_version, 'idle_warning', $device->id,
                $this->devices()->where('id', $device->id), $device->activity_version);
        }
    }

    public function deliverable(stdClass $delivery, stdClass $device): bool
    {
        if ($device->revoked_at || $delivery->binding_id !== $device->binding_id || ! $this->allowed((int) $device->principal_id)) {
            return false;
        }
        if (! $this->isChat()) {
            return DB::table('users')->where('id', $device->principal_id)->exists()
                && DB::table('tickets')->where('id', $delivery->record_id)->whereNotIn('folder', ['spam', 'trash'])->whereNull('merged_into_id')->exists();
        }
        $agent = LiveChatAgent::find($device->principal_id);
        if (! $agent?->active || (int) $agent->auth_version !== (int) $device->auth_version) {
            return false;
        }
        if ($delivery->type === 'idle_warning') {
            return $delivery->condition === $device->activity_version && $device->active_until && Carbon::parse($device->active_until)->isFuture()
                && ! $this->otherDeviceOnline($device);
        }
        $thread = LiveChatThread::find($delivery->record_id);

        return $thread && ! $thread->inbox_deleted_at && $thread->status === 'waiting'
            && app(LiveChat::class)->humanSupport($thread->shop_name)
            && LiveChatAgent::available()->whereKey($agent->id)->exists();
    }

    public function process(): void
    {
        if (! config('mobile.enabled')) {
            return;
        }
        $this->enqueueIdleWarnings();
        $query = DB::table('mobile_push_deliveries')->where('available_at', '<=', now())
            ->where(fn (Builder $query) => $query->whereIn('status', ['pending', 'retry', 'receipt'])
                ->orWhere(fn (Builder $query) => $query->where('status', 'processing')->where('updated_at', '<', now()->subMinutes(2))));
        foreach ($query->orderBy('available_at')->limit(50)->get() as $delivery) {
            $claimed = DB::table('mobile_push_deliveries')->where('id', $delivery->id)->where('status', $delivery->status)
                ->where('updated_at', $delivery->updated_at)->update(['status' => 'processing', 'updated_at' => now()]);
            if (! $claimed) {
                continue;
            }
            $device = DB::table('mobile_devices')->where('id', $delivery->device_id)->first();
            if (! $device || $device->revoked_at || $delivery->binding_id !== $device->binding_id
                || (! $delivery->receipt_id && ! $this->deliverable($delivery, $device))) {
                $this->update($delivery, ['status' => 'cancelled']);

                continue;
            }
            if (Carbon::parse($delivery->created_at)->lt(now()->subDay())) {
                $this->update($delivery, ['status' => 'expired']);

                continue;
            }
            try {
                $http = Http::acceptJson()->asJson()->timeout(15)->connectTimeout(5);
                if (config('mobile.expo_access_token')) {
                    $http = $http->withToken(config('mobile.expo_access_token'));
                }
                if ($delivery->receipt_id) {
                    $response = $http->post('https://exp.host/--/api/v2/push/getReceipts', ['ids' => [$delivery->receipt_id]]);
                    $result = $response->json('data.'.$delivery->receipt_id);
                } else {
                    [$channel, $sound, $phrase] = self::SOUNDS[$delivery->type];
                    $ttl = $delivery->type === 'idle_warning' ? max(1, (int) now()->diffInSeconds(Carbon::parse($device->active_until), false)) : 300;
                    $response = $http->post('https://exp.host/--/api/v2/push/send', [
                        'to' => $device->push_token, 'title' => 'Areviews Support', 'body' => $phrase,
                        'sound' => $sound, 'channelId' => $channel, 'priority' => 'high', 'ttl' => $ttl,
                        'data' => ['event_id' => $delivery->event_id, 'type' => $delivery->type, 'record_id' => $delivery->record_id],
                    ]);
                    $result = $response->json('data');
                }
                if ($response->status() === 429 || $response->serverError()) {
                    $this->retry($delivery, 'Provider temporarily unavailable');
                } elseif (! $response->successful()) {
                    $this->update($delivery, ['status' => 'failed', 'last_error' => 'Provider HTTP '.$response->status()]);
                } elseif (! is_array($result)) {
                    $this->retry($delivery, 'Waiting for provider result');
                } elseif (($result['status'] ?? '') === 'error') {
                    $error = $result['details']['error'] ?? 'ProviderError';
                    if ($error === 'DeviceNotRegistered') {
                        DB::table('mobile_devices')->where('id', $device->id)->where('binding_id', $device->binding_id)->update(['revoked_at' => now(), 'active_until' => null]);
                    }
                    if ($error === 'MessageRateExceeded') {
                        $this->retry($delivery, $error);
                    } else {
                        $this->update($delivery, ['status' => 'failed', 'last_error' => $error]);
                    }
                } elseif (($result['status'] ?? '') === 'ok' && $delivery->receipt_id) {
                    $this->update($delivery, ['status' => 'delivered']);
                } elseif (($result['status'] ?? '') === 'ok' && is_string($result['id'] ?? null)) {
                    $this->update($delivery, ['status' => 'receipt', 'receipt_id' => $result['id'], 'available_at' => now()->addMinutes(15), 'attempts' => 0]);
                } else {
                    $this->retry($delivery, 'Invalid provider result');
                }
            } catch (ConnectionException $exception) {
                $this->retry($delivery, 'Provider connection failed');
            }
        }
    }

    private function retry(stdClass $delivery, string $error): void
    {
        $attempts = $delivery->attempts + 1;
        $this->update($delivery, ['status' => $attempts >= 8 ? 'failed' : ($delivery->receipt_id ? 'receipt' : 'retry'),
            'attempts' => $attempts, 'last_error' => $error, 'available_at' => now()->addSeconds(min(900, 15 * (2 ** $attempts)))]);
    }

    private function update(stdClass $delivery, array $values): void
    {
        DB::table('mobile_push_deliveries')->where('id', $delivery->id)->update([...$values, 'updated_at' => now()]);
    }
}
