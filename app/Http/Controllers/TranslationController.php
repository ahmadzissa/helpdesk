<?php

namespace App\Http\Controllers;

use App\Jobs\SendTicketReply;
use App\Models\Activity;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\WorkspaceSetting;
use App\Services\EmailContent;
use App\Services\MailSafety;
use App\Services\TranslationPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class TranslationController extends Controller
{
    public function config(TranslationPolicy $policy): JsonResponse
    {
        return response()->json(['settings' => $policy->settings(), 'key' => $policy->browserKey()])
            ->header('Cache-Control', 'private, no-store');
    }

    public function settings(Request $request, TranslationPolicy $policy): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate(['incoming' => 'required|boolean', 'outgoing' => 'required|boolean',
            'auto_send' => 'sometimes|boolean',
            'target' => ['required', 'string', TranslationPolicy::LANGUAGE_RULE],
            'key' => 'nullable|string|min:20|max:250']);
        DB::transaction(function () use ($request, $data, $policy) {
            $settings = [...collect($data)->only(['incoming', 'outgoing', 'target'])->all(),
                'auto_send' => $data['auto_send'] ?? $policy->settings()['auto_send'], 'revision' => $policy->settings()['revision'] + 1];
            WorkspaceSetting::updateOrCreate(['key' => 'translation'], ['value' => $settings]);
            if (! empty($data['key'])) {
                WorkspaceSetting::updateOrCreate(['key' => 'translation_key'], ['value' => ['encrypted' => Crypt::encryptString($data['key'])]]);
            }
            Activity::create(['user_id' => $request->user()->id, 'description' => 'Updated email translation settings.']);
        });

        return response()->json(['settings' => $policy->settings(), 'has_key' => $policy->browserKey() !== '']);
    }

    public function language(Request $request, Ticket $ticket, TranslationPolicy $policy): JsonResponse
    {
        $data = $request->validate(['language' => ['present', 'nullable', 'string', TranslationPolicy::LANGUAGE_RULE]]);
        DB::transaction(function () use ($ticket, $data, $policy, $request) {
            DB::table('customer_languages')->updateOrInsert(['email' => $ticket->requester_email],
                ['language' => $data['language'], 'manual' => $data['language'] !== null,
                    'source_message_id' => $data['language'] ? $policy->latestInbound($ticket)?->id : null, 'updated_at' => now(), 'created_at' => now()]);
            Activity::create(['ticket_id' => $ticket->id, 'user_id' => $request->user()->id,
                'description' => 'Customer language '.($data['language'] ? 'set to '.$data['language'] : 'reset to automatic detection').'.']);
        });

        return response()->json(['customer_language' => $policy->customer($ticket), 'translation_context' => $policy->context($ticket)]);
    }

    public function message(Request $request, Message $message, TranslationPolicy $policy): JsonResponse
    {
        abort_if($message->kind === 'note', 422, 'Private notes are not email translations.');
        $data = $request->validate(['target_language' => ['required', 'string', TranslationPolicy::LANGUAGE_RULE],
            'source_language' => ['nullable', 'string', TranslationPolicy::LANGUAGE_RULE],
            'source_hash' => 'required|string|size:64', 'body' => 'required|string|max:500000', 'body_format' => 'sometimes|in:text,html']);
        $data['body_format'] ??= 'text';
        $data['body'] = app(EmailContent::class)->withoutTrackingLabels($data['body'], $message);
        abort_unless(trim($data['body']) !== '', 422, 'The translation contains no message text. Translate the message again.');
        abort_if($data['body_format'] === 'html' && ($message->kind !== 'inbound' || ! $message->email_html), 422, 'Only formatted incoming emails accept an HTML translation.');
        DB::transaction(function () use ($message, $data, $policy) {
            $message = Message::whereKey($message->id)->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals(hash('sha256', $message->body), $data['source_hash']), 409, 'The original message changed. Translate it again.');
            DB::table('message_translations')->updateOrInsert(['message_id' => $message->id, 'target_language' => $data['target_language']],
                [...$data, 'created_at' => now(), 'updated_at' => now()]);
            $policy->detect($message, $data['source_language'] ?? null);
        });

        return response()->json(['customer_language' => $policy->customer($message->ticket),
            'translation_context' => $policy->context($message->ticket),
            'translation' => [...$data, 'body_html' => app(EmailContent::class)->render($message, $data['body'], $data['body_format'])]]);
    }

    public function subject(Request $request, Ticket $ticket): JsonResponse
    {
        $data = $request->validate(['target_language' => ['required', 'string', TranslationPolicy::LANGUAGE_RULE],
            'source_hash' => 'required|string|size:64', 'subject' => 'required|string|max:2000']);
        abort_unless(hash_equals(hash('sha256', $ticket->subject), $data['source_hash']), 409, 'The ticket subject changed. Translate it again.');
        DB::table('ticket_translations')->updateOrInsert(['ticket_id' => $ticket->id, 'target_language' => $data['target_language']],
            [...$data, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['translation' => $data]);
    }

    public function prepare(Request $request, Message $message, TranslationPolicy $policy): JsonResponse
    {
        $data = $request->validate(['body' => 'required|string|max:50000', 'assignee_id' => 'sometimes|nullable|integer|exists:users,id', ...$policy->rules(), 'translation' => 'required_unless:send_original,true|nullable|array:original_body,subject,source_language,context']);
        DB::transaction(function () use ($message, $data, $policy, $request) {
            $message = Message::whereKey($message->id)->lockForUpdate()->firstOrFail();
            $message->ticket->assertWritable();
            abort_unless($message->kind === 'outbound' && in_array($message->delivery, ['translation_pending', 'held', 'saved']) && ! $message->attempt_id, 409, 'Only an unsent reply can be translated here.');
            $sendOriginal = (bool) ($data['send_original'] ?? false);
            abort_unless(($sendOriginal ? $data['body'] : $data['translation']['original_body']) === ($message->original_body ?? $message->body), 409, 'The reply changed. Reload and translate it again.');
            $translated = $policy->outgoing($message->ticket, $data['body'], $data['translation'] ?? null, $sendOriginal);
            $message->update(['body' => $data['body'], ...$translated, ...app(MailSafety::class)->prepare($message->ticket->mailbox)]);
            $message->ticket->update(['assignee_id' => array_key_exists('assignee_id', $data) ? $data['assignee_id'] : $request->user()->id]);
            Activity::create(['ticket_id' => $message->ticket_id, 'user_id' => $request->user()->id, 'description' => ($sendOriginal ? 'Chose original language for reply #' : 'Prepared browser translation for reply #').$message->id.'.']);
            if ($message->delivery === 'queued') {
                if ($message->ticket->status === 'Open') {
                    $message->ticket->update(['status' => 'Pending', 'resolved_at' => null]);
                }
                SendTicketReply::dispatch($message)->afterCommit();
            }
        });

        return response()->json(['message' => 'Translated reply saved.', 'delivery' => $message->fresh()->delivery]);
    }
}
