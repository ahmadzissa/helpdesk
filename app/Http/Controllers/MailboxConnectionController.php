<?php

namespace App\Http\Controllers;

use App\Models\Mailbox;
use App\Services\MailboxConnections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MailboxConnectionController extends Controller
{
    public function test(Request $request, MailboxConnections $connections, ?Mailbox $mailbox = null): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $mailbox ??= new Mailbox;
        $rules = [];
        foreach (['smtp', 'imap'] as $protocol) {
            $rules[$protocol.'_host'] = ['required', 'string', 'regex:/^[a-zA-Z0-9.-]+$/', 'max:255'];
            $rules[$protocol.'_port'] = 'required|integer|between:1,65535';
            $rules[$protocol.'_encryption'] = 'required|in:tls,ssl';
            $rules[$protocol.'_username'] = ($protocol === 'imap' ? 'required' : 'nullable').'|string|max:255';
            $rules[$protocol.'_password'] = 'nullable|string|max:1000';
        }
        $data = $request->validate($rules);
        foreach (['smtp_password', 'imap_password'] as $field) {
            if (($data[$field] ?? '') === '') {
                unset($data[$field]);
            }
        }
        $mailbox->fill($data);
        Validator::make($connections->settings($mailbox), [
            'imap_password' => 'required|string', 'smtp_password' => $mailbox->smtp_username ? 'required|string' : 'nullable|string',
        ], ['required' => 'Enter the :attribute before testing.'])->validate();

        return response()->json($connections->test($mailbox, $request->user()->id))->header('Cache-Control', 'no-store, private');
    }
}
