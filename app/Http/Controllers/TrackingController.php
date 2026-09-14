<?php

namespace App\Http\Controllers;

use App\Services\DeliveryTracking;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

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

    public function optOut(Request $request, string $attempt): Response
    {
        $record = DB::table('mail_delivery_attempts')->where('id', $attempt)->first();
        abort_unless($record, 404);
        if ($request->isMethod('post')) {
            app(DeliveryTracking::class)->record($attempt, 'opt_out', 'optout:'.$attempt, $record->recipient, 'Recipient confirmed the email preference link.');

            return response()->view('mail-preferences', ['email' => $record->recipient, 'complete' => true, 'action' => null]);
        }

        return response()->view('mail-preferences', ['email' => $record->recipient, 'complete' => false, 'action' => URL::signedRoute('mail.optout', ['attempt' => $attempt])]);
    }
}
