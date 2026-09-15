<?php

namespace App\Http\Controllers;

use App\Services\DeliveryTracking;
use App\Services\EmailPreferences;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class TrackingController extends Controller
{
    public function open(string $attempt): Response
    {
        if (DB::table('mail_delivery_attempts')->where('id', $attempt)->exists()) {
            app(DeliveryTracking::class)->record($attempt, 'opened', 'pixel:'.$attempt, null, 'Email open detected; this may be an email privacy service.');
        }

        return response(base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), 200, [
            'Content-Type' => 'image/gif', 'Cache-Control' => 'private, no-store, max-age=0', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function optOut(Request $request, string $attempt, EmailPreferences $preferences): Response
    {
        $record = DB::table('mail_delivery_attempts')->where('id', $attempt)->first();
        if (! $record) {
            return response()->view('mail-preferences', ['state' => 'invalid'], 404, ['Cache-Control' => 'private, no-store']);
        }

        $updated = false;
        $error = null;
        if ($request->isMethod('post')) {
            $preference = $request->input('preference', 'stop');
            if (! in_array($preference, ['stop', 'resume'], true)) {
                $error = 'Choose whether to stop or resume automatic and scheduled email notifications.';
            } else {
                $updated = $preferences->update($attempt, $preference);
            }
        }

        return response()->view('mail-preferences', [
            'email' => $record->recipient, 'state' => $preferences->status($record->recipient),
            'updated' => $updated, 'error' => $error, 'action' => $request->fullUrl(),
        ], $error ? 422 : 200, ['Cache-Control' => 'private, no-store']);
    }
}
