<?php

namespace App\Console\Commands;

use App\Models\Message;
use App\Services\ImapInbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class RepairEmailFormatting extends Command
{
    protected $signature = 'mailboxes:repair-formatting {--message= : The existing incoming message ID to repair}';

    protected $description = 'Recover original HTML and inline image references for one imported email without importing it again';

    public function handle(ImapInbox $inbox): int
    {
        $id = (string) $this->option('message');
        if (! ctype_digit($id)) {
            $this->error('Provide an existing incoming message using --message=ID.');

            return self::FAILURE;
        }
        $message = Message::with('ticket.mailbox')->whereKey($id)->where('kind', 'inbound')->first();
        $mailbox = $message?->ticket?->mailbox;
        if (! $message || ! $mailbox?->incoming_enabled || ! $message->external_id || $message->mailbox_id !== $mailbox->id) {
            $this->error('The incoming message and its connected mailbox must still exist.');

            return self::FAILURE;
        }
        if ($message->email_html) {
            $this->info('This message already has its original formatting.');

            return self::SUCCESS;
        }
        $original = $inbox->findMessage($mailbox, $message->external_id);
        if (! $original?->getHTMLBody()) {
            $this->error('The original HTML email was not found in the connected inbox.');

            return self::FAILURE;
        }
        $files = $message->attachments ?? [];
        foreach ($original->getAttachments() as $attachment) {
            foreach ($files as &$file) {
                if (($file['size'] ?? 0) === strlen((string) $attachment->content) && Storage::disk('local')->exists($file['path'])
                    && hash_equals(hash('sha256', Storage::disk('local')->get($file['path'])), hash('sha256', (string) $attachment->content))) {
                    $file['content_id'] = mb_substr(trim((string) $attachment->id, '<> '), 0, 255);
                    $file['mime'] = Storage::disk('local')->mimeType($file['path']);
                    break;
                }
            }
            unset($file);
        }
        DB::transaction(function () use ($message, $original, $files): void {
            $current = Message::whereKey($message->id)->lockForUpdate()->first();
            if (! $current || $current->email_html || $current->attachments !== $message->attachments) {
                return;
            }
            $current->forceFill(['email_html' => mb_substr($original->getHTMLBody(), 0, 500000), 'attachments' => $files])->save();
            DB::table('message_translations')->where('message_id', $current->id)->delete();
        });
        $this->info('Recovered email formatting for message #'.$message->id.'. No ticket was imported or moved.');

        return self::SUCCESS;
    }
}
