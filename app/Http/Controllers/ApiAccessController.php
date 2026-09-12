<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ApiAccessController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);

        return response()->json(['base_url' => url('/api/v1/external'), 'keys' => DB::table('api_keys')->orderByDesc('id')->get(['id', 'name', 'prefix', 'mailbox_id', 'scopes', 'expires_at', 'last_used_at', 'revoked_at', 'created_at'])->map(fn ($key) => [...(array) $key, 'scopes' => json_decode($key->scopes, true)])]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate(['name' => 'required|string|max:100', 'mailbox_id' => 'required|integer|exists:mailboxes,id', 'scopes' => 'required|array|min:1|max:2', 'scopes.*' => 'required|in:tickets:create,delivery:write|distinct', 'expires_at' => 'nullable|date|after:now']);
        $token = 'rly_'.Str::random(64);
        $id = DB::table('api_keys')->insertGetId([...$data, 'expires_at' => empty($data['expires_at']) ? null : Carbon::parse($data['expires_at'])->utc(), 'user_id' => $request->user()->id, 'prefix' => substr($token, 0, 12), 'token_hash' => hash('sha256', $token), 'scopes' => json_encode($data['scopes']), 'created_at' => now(), 'updated_at' => now()]);
        Activity::create(['user_id' => $request->user()->id, 'description' => 'Created API key: '.$data['name']]);

        return response()->json(['id' => $id, 'token' => $token, 'message' => 'Copy this key now. It will not be shown again.'], 201)->header('Cache-Control', 'no-store');
    }

    public function revoke(Request $request, int $key): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        DB::table('api_keys')->where('id', $key)->update(['revoked_at' => now(), 'updated_at' => now()]);
        Activity::create(['user_id' => $request->user()->id, 'description' => 'Revoked API key #'.$key]);

        return response()->json(['revoked' => true]);
    }
}
