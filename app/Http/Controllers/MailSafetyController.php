<?php

namespace App\Http\Controllers;

use App\Services\MailSafety;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MailSafetyController extends Controller
{
    public function show(Request $request, MailSafety $safety): JsonResponse
    {
        $data = $safety->status();
        if ($request->user()->role === 'admin' && $request->boolean('review')) {
            $data['failures'] = DB::table('mail_delivery_attempts as attempts')
                ->leftJoin('messages', 'messages.id', '=', 'attempts.message_id')
                ->leftJoin('mailboxes', 'mailboxes.id', '=', 'attempts.mailbox_id')
                ->whereNotNull('attempts.failed_at')->orderByDesc('attempts.failed_at')->limit(30)
                ->get(['attempts.id', 'attempts.recipient', 'attempts.failed_at', 'attempts.failure_kind', 'attempts.error', 'messages.ticket_id', 'mailboxes.name as mailbox_name'])
                ->map(function (object $failure) {
                    $failure->failed_at = Carbon::parse($failure->failed_at)->toIso8601String();

                    return $failure;
                });
            $data['held'] = DB::table('messages')->join('tickets', 'tickets.id', '=', 'messages.ticket_id')
                ->where('tickets.folder', 'inbox')->whereNull('tickets.merged_into_id')
                ->where('messages.delivery', 'held')->orderBy('messages.id')->limit(30)
                ->get(['messages.id', 'messages.ticket_id', 'tickets.subject', 'tickets.requester_email']);
        }

        return response()->json($data);
    }

    public function configure(Request $request, MailSafety $safety): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate(['threshold' => 'required|integer|min:1|max:1000', 'window_minutes' => 'required|integer|min:1|max:1440']);
        $safety->configure($data['threshold'], $data['window_minutes'], $request->user()->id);

        return response()->json($safety->status());
    }

    public function pause(Request $request, MailSafety $safety): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $safety->manualPause($request->user()->id);

        return response()->json($safety->status());
    }

    public function resume(Request $request, MailSafety $safety): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate(['reviewed' => 'required|accepted', 'review' => 'required|string|min:5|max:180', 'epoch' => 'required|integer|min:0']);
        $safety->resume($request->user()->id, $data['review'], $data['epoch']);

        return response()->json($safety->status());
    }
}
