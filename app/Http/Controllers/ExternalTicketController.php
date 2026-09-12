<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Mailbox;
use App\Models\Ticket;
use App\Services\AutomationEngine;
use App\Services\DeliveryTracking;
use App\Services\SenderPolicy;
use App\Services\TicketCustomFields;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ExternalTicketController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $key = $request->attributes->get('api_key');
        abort_unless(in_array('tickets:create', $key->scopes, true), 403, 'This key cannot create tickets.');
        $data = $request->validate(['subject' => 'required|string|max:255', 'requester_email' => 'required|email|max:255', 'requester_name' => 'nullable|string|max:100', 'body' => 'required|string|max:50000', 'priority' => 'sometimes|in:Low,Normal,High,Urgent', 'tags' => 'sometimes|array|max:20', 'tags.*' => 'required|string|max:60', 'custom_fields' => 'sometimes|array|max:20', 'custom_fields.*' => 'nullable|string|max:500']);
        $requestKey = $request->header('Idempotency-Key');
        Validator::make(['key' => $requestKey], ['key' => 'required|string|max:100|regex:/^[a-zA-Z0-9._:-]+$/'])->validate();
        $data['requester_email'] = mb_strtolower(trim($data['requester_email']));
        ksort($data);
        $hash = hash('sha256', json_encode($data));
        $replayed = false;
        $ticket = DB::transaction(function () use ($key, $requestKey, $hash, $data, &$replayed) {
            $locked = DB::table('api_keys')->where('id', $key->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->revoked_at, 401, 'The key was revoked.');
            $existing = DB::table('api_ticket_requests')->where('api_key_id', $key->id)->where('request_key', $requestKey)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'This Idempotency-Key was used with different ticket details.');
                $replayed = true;

                return Ticket::findOrFail($existing->ticket_id);
            }
            $mailbox = Mailbox::findOrFail($key->mailbox_id);
            app(TicketCustomFields::class)->validateValues($data['custom_fields'] ?? []);
            $ticket = Ticket::create([...collect($data)->except('body')->all(), 'mailbox_id' => $mailbox->id, 'team_id' => $mailbox->team_id, 'source' => 'Webhook', 'folder' => app(SenderPolicy::class)->classification($data['requester_email']) === 'blocked' ? 'spam' : 'inbox', 'last_activity_at' => now(), 'unread' => true]);
            $ticket->messages()->create(['kind' => 'inbound', 'body' => $data['body'], 'author_email' => $data['requester_email'], 'author_name' => $data['requester_name'] ?? null]);
            DB::table('api_ticket_requests')->insert(['api_key_id' => $key->id, 'request_key' => $requestKey, 'request_hash' => $hash, 'ticket_id' => $ticket->id]);
            Activity::create(['ticket_id' => $ticket->id, 'description' => 'Ticket created through API key “'.$key->name.'”.']);
            app(AutomationEngine::class)->run($ticket, 'ticket.created');
            app(AutomationEngine::class)->run($ticket, 'message.received');

            return $ticket->fresh();
        }, 5);

        return response()->json(['ticket' => $ticket->only(['id', 'subject', 'status', 'requester_email', 'created_at']), 'url' => url('/tickets/'.$ticket->id), 'replayed' => $replayed], $replayed ? 200 : 201);
    }

    public function delivery(Request $request): JsonResponse
    {
        $key = $request->attributes->get('api_key');
        abort_unless(in_array('delivery:write', $key->scopes, true), 403, 'This key cannot report delivery events.');
        $data = $request->validate([
            'event_id' => 'required|string|max:100|regex:/^[a-zA-Z0-9._:-]+$/', 'attempt_id' => 'sometimes|required|uuid',
            'message_id' => 'required_without:attempt_id|string|max:255',
            'type' => 'required|in:delivered,failed,opened,complaint,opt_out', 'recipient' => 'required|email|max:255',
            'detail' => 'nullable|string|max:255', 'occurred_at' => 'nullable|date|before:'.now()->addMinutes(5)->toIso8601String(),
        ]);
        $query = DB::table('mail_delivery_attempts')->where('mailbox_id', $key->mailbox_id);
        $query = isset($data['attempt_id']) ? $query->where('id', $data['attempt_id']) : $query->where('external_id', trim($data['message_id'], '<>'));
        $attempt = $query->first();
        abort_unless($attempt, 404, 'Unknown delivery attempt for this mailbox.');
        app(DeliveryTracking::class)->record($attempt->id, $data['type'], 'api:'.$key->id.':'.$data['event_id'], $data['recipient'], $data['detail'] ?? null, empty($data['occurred_at']) ? now() : Carbon::parse($data['occurred_at'])->utc());

        return response()->json(['recorded' => true]);
    }
}
