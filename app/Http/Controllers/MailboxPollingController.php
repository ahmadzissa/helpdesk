<?php

namespace App\Http\Controllers;

use App\Jobs\SyncMailbox;
use App\Models\Mailbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Throwable;

class MailboxPollingController extends Controller
{
    public function __invoke(): JsonResponse
    {
        abort_unless(app()->environment('local'), 404);
        $checked = 0;
        $errors = [];
        $mailboxes = Mailbox::where('incoming_enabled', true)
            ->where(fn ($query) => $query->whereNull('last_synced_at')->orWhere('last_synced_at', '<=', now()->subMinute()))
            ->orderBy('last_synced_at')->orderBy('id')->get(['id', 'name']);

        foreach ($mailboxes as $mailbox) {
            if (! Cache::add('local-mail-poll:'.$mailbox->id, true, 60)) {
                continue;
            }
            try {
                set_time_limit(930);
                $startedAt = now()->startOfSecond();
                SyncMailbox::dispatchSync($mailbox->id);
                if (Mailbox::find($mailbox->id)?->last_synced_at?->greaterThanOrEqualTo($startedAt)) {
                    $checked++;
                }
            } catch (Throwable $exception) {
                report($exception);
                $errors[] = ['mailbox_id' => $mailbox->id, 'message' => 'Incoming mail could not be checked for '.$mailbox->name.'. Check its connection settings.'];
            }
        }

        return response()->json(['checked' => $checked, 'errors' => $errors]);
    }
}
