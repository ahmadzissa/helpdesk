<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\WorkspaceSetting;
use App\Services\SenderPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MailPolicyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);

        return response()->json([
            'senders' => DB::table('sender_rules')->orderBy('value')->get(),
            'suppressions' => DB::table('recipient_suppressions')->orderByDesc('id')->paginate(50),
            'settings' => array_replace(['track_opens' => true, 'recovery_mailbox_id' => null], WorkspaceSetting::find('mail_policy')?->value ?? []),
            'public_url' => config('app.url'),
        ]);
    }

    public function settings(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate(['track_opens' => 'required|boolean', 'recovery_mailbox_id' => 'nullable|integer|exists:mailboxes,id']);
        WorkspaceSetting::updateOrCreate(['key' => 'mail_policy'], ['value' => $data]);

        return response()->json($data);
    }

    public function sender(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $request->merge(['value' => mb_strtolower(trim($request->input('value', '')))]);
        $data = $request->validate(['kind' => 'required|in:email,domain', 'value' => ['required', 'string', 'max:255', $request->input('kind') === 'email' ? 'email' : 'regex:/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/'], 'action' => 'required|in:blocked,trusted', 'include_subdomains' => 'required|boolean']);
        DB::table('sender_rules')->upsert([...$data, 'created_at' => now(), 'updated_at' => now()], ['kind', 'value'], ['action', 'include_subdomains', 'updated_at']);
        Activity::create(['user_id' => $request->user()->id, 'description' => mb_substr('Sender rule: '.$data['value'].' '.$data['action'], 0, 255)]);

        return response()->json(['saved' => true]);
    }

    public function suppress(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate(['email' => 'required|email|max:255', 'reason' => ['required', Rule::in(['manual', 'complaint', 'opt_out', 'hard_bounce'])], 'notes' => 'nullable|string|max:255']);
        app(SenderPolicy::class)->suppress($data['email'], $data['reason'], $data['notes'] ?? null);

        return response()->json(['saved' => true]);
    }

    public function destroy(Request $request, string $kind, int $id): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        abort_unless(in_array($kind, ['senders', 'suppressions']), 404);
        $data = $request->validate(['review' => 'required|string|min:10|max:160']);
        DB::table($kind === 'senders' ? 'sender_rules' : 'recipient_suppressions')->where('id', $id)->delete();
        Activity::create(['user_id' => $request->user()->id, 'description' => 'Removed '.$kind.' #'.$id.' after review: '.$data['review']]);

        return response()->json(['deleted' => true]);
    }
}
