<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class IncomingMail
{
    public function __construct(private AutomationEngine $automations) {}

    /**
     * @param  array{external_id:string, from_email:string, from_name?:string, reply_to_email?:string, reply_to_name?:string, subject:string, body:string, references?:array, automated?:bool, attachments?:array}  $data
     */
    public function import(Mailbox $mailbox, array $data): ?Ticket
    {
        $headers = app(EmailHeaders::class);
        $data['subject'] = $headers->decode($data['subject']);
        $data['from_name'] = $headers->decode($data['from_name'] ?? '');
        $email = mb_strtolower(trim($data['from_email']));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        if ($email === mb_strtolower(trim($mailbox->email))) {
            $replyToEmail = mb_strtolower(trim($data['reply_to_email'] ?? ''));
            if (! filter_var($replyToEmail, FILTER_VALIDATE_EMAIL) || $replyToEmail === $email
                || Mailbox::whereRaw('LOWER(TRIM(email)) = ?', [$replyToEmail])->exists()) {
                return null;
            }
            $email = $replyToEmail;
            $data['from_name'] = $headers->decode($data['reply_to_name'] ?? '');
            $data['automated'] = true;
        }
        $externalId = mb_substr($data['external_id'], 0, 255);
        if (Message::where('mailbox_id', $mailbox->id)->where('external_id', $externalId)->exists()) {
            return null;
        }
        $files = [];
        try {
            return DB::transaction(function () use ($mailbox, $data, $email, $externalId, &$files) {
                $claimed = DB::table('mail_import_receipts')->insertOrIgnore([
                    'mailbox_key' => hash('sha256', mb_strtolower(trim($mailbox->email))),
                    'message_key' => hash('sha256', $externalId), 'created_at' => now(),
                ]);
                if (! $claimed) {
                    return null;
                }
                $existing = null;
                $references = array_slice($data['references'] ?? [], -30);
                if ($references !== []) {
                    $parent = Message::where('mailbox_id', $mailbox->id)->whereIn('external_id', $references)->whereHas('ticket', fn ($q) => $q->whereRaw('LOWER(requester_email) = ?', [$email]))->latest('id')->first();
                    $existing = $parent?->ticket;
                }
                if (! $existing && preg_match('/\[#(\d+)\]/', $data['subject'], $match)) {
                    $existing = Ticket::whereKey($match[1])->where('mailbox_id', $mailbox->id)->whereRaw('LOWER(requester_email) = ?', [$email])->first();
                }
                if ($existing?->merged_into_id) {
                    $existing = Ticket::whereKey($existing->merged_into_id)->where('mailbox_id', $mailbox->id)->whereRaw('LOWER(requester_email) = ?', [$email])->first();
                }
                if ($existing) {
                    $existing = Ticket::whereKey($existing->id)->lockForUpdate()->firstOrFail();
                }
                $classification = app(SenderPolicy::class)->classification($email);
                $ticket = $existing ?? Ticket::create([
                    'subject' => mb_substr(trim($data['subject']) ?: '(No subject)', 0, 255),
                    'requester_name' => mb_substr($data['from_name'] ?? '', 0, 100) ?: null,
                    'requester_email' => $email, 'mailbox_id' => $mailbox->id, 'team_id' => $mailbox->team_id,
                    'status' => 'Open', 'source' => 'Email', 'folder' => 'inbox', 'tags' => [], 'last_activity_at' => now(),
                ]);
                $skipped = 0;
                foreach ($data['attachments'] ?? [] as $file) {
                    if (count($files) >= 5 || strlen($file['content']) > 10 * 1024 * 1024) {
                        $skipped++;

                        continue;
                    }
                    $path = 'ticket-attachments/'.Str::uuid();
                    Storage::disk('local')->put($path, $file['content']);
                    $files[] = ['path' => $path, 'name' => mb_substr(basename(str_replace('\\', '/', app(EmailHeaders::class)->decode($file['name']))), 0, 200), 'size' => strlen($file['content']),
                        'content_id' => mb_substr(trim($file['content_id'] ?? '', '<> '), 0, 255), 'mime' => (new \finfo(FILEINFO_MIME_TYPE))->buffer($file['content'])];
                }
                $message = $ticket->messages()->make(['mailbox_id' => $mailbox->id, 'external_id' => $externalId, 'kind' => 'inbound',
                    'author_name' => mb_substr($data['from_name'] ?? '', 0, 100), 'author_email' => $email,
                    'body' => mb_substr($data['body'] ?: '(Empty message)', 0, 50000), 'attachments' => $files]);
                $message->forceFill(['email_html' => empty($data['email_html']) ? null : mb_substr($data['email_html'], 0, 500000)])->save();
                $changes = ['unread' => true, 'last_activity_at' => now()];
                if (! in_array($ticket->folder, ['spam', 'trash'])) {
                    $changes += ['folder' => 'inbox', 'status' => 'Open', 'resolved_at' => null];
                }
                if ($classification === 'blocked') {
                    $changes['folder'] = 'spam';
                } elseif ($classification === 'trusted' && $ticket->folder === 'spam') {
                    $changes += ['status' => 'Open', 'resolved_at' => null];
                    $changes['folder'] = 'inbox';
                }
                $ticket->update($changes);
                DB::table('follow_ups')->where('ticket_id', $ticket->id)->where('state', 'pending')->where('cancel_on_reply', true)->update(['state' => 'cancelled', 'result' => 'Customer replied', 'updated_at' => now()]);
                Activity::create(['ticket_id' => $ticket->id, 'description' => 'Received email for #'.$ticket->id.($skipped ? ' · '.$skipped.' attachment(s) exceeded the import limits' : '')]);
                if (! ($data['automated'] ?? false)) {
                    $this->automations->run($ticket, $existing ? 'ticket.updated' : 'ticket.created');
                    $this->automations->run($ticket, 'message.received');
                }

                return $ticket;
            });
        } catch (\Throwable $exception) {
            foreach ($files as $file) {
                Storage::disk('local')->delete($file['path']);
            }
            throw $exception;
        }
    }
}
