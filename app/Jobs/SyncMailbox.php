<?php

namespace App\Jobs;

use App\Models\Mailbox;
use App\Services\DeliveryReport;
use App\Services\ImapInbox;
use App\Services\IncomingMail;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;
use Webklex\PHPIMAP\Message as ImapMessage;

class SyncMailbox implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = 1200;

    public function __construct(public int $mailboxId)
    {
        $this->onQueue('incoming');
    }

    public function uniqueId(): string
    {
        return (string) $this->mailboxId;
    }

    public function handle(IncomingMail $importer, ImapInbox $inbox): void
    {
        $lock = Cache::lock('imap-import-'.$this->mailboxId, 950);
        if (! $lock->get()) {
            return;
        }
        $mailbox = null;
        $started = now();
        try {
            $mailbox = Mailbox::find($this->mailboxId);
            if (! $mailbox?->incoming_enabled) {
                return;
            }
            $mailbox->update(['sync_status' => 'syncing', 'sync_error' => null]);
            $inbox->receive($mailbox, function (ImapMessage $message) use ($mailbox, $importer): void {
                $reports = app(DeliveryReport::class);
                if ($reports->consume($mailbox, $message)) {
                    return;
                }
                $sender = $message->get('from')->first();
                if (! $sender) {
                    return;
                }
                $replyToAddresses = $message->get('reply_to');
                $replyTo = $replyToAddresses->count() === 1 ? $replyToAddresses->first() : null;
                $rawId = trim((string) $message->get('message_id'), '<> ');
                $body = $message->getTextBody();
                if (! $body) {
                    $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $message->getHTMLBody());
                    $body = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
                $references = preg_split('/[\s,<>]+/', (string) $message->get('references').' '.(string) $message->get('in_reply_to'), -1, PREG_SPLIT_NO_EMPTY);
                $auto = strtolower((string) $message->get('auto_submitted'));
                $precedence = strtolower((string) $message->get('precedence'));
                $attachments = [];
                foreach ($message->getAttachments() as $attachment) {
                    $attachments[] = ['name' => (string) $attachment->name, 'content' => (string) $attachment->content, 'content_id' => (string) $attachment->id];
                }
                $importer->import($mailbox, ['external_id' => $rawId ?: (string) $message->get('relay_import_fingerprint'),
                    'received_at' => (string) $message->get('relay_received_at') ?: null,
                    'from_email' => $sender->mail, 'from_name' => $sender->personal, 'subject' => (string) $message->get('subject'),
                    'reply_to_email' => $replyTo?->mail ?? '', 'reply_to_name' => $replyTo?->personal ?? '',
                    'body' => $body, 'email_html' => $message->getHTMLBody(), 'references' => $references, 'attachments' => $attachments,
                    'automated' => $reports->isReport($message) || ($auto !== '' && $auto !== 'no') || in_array($precedence, ['bulk', 'list', 'junk'])]);
            });
            $mailbox->update(['last_synced_at' => $started, 'sync_status' => 'idle', 'sync_error' => null]);
        } catch (Throwable $exception) {
            $mailbox?->update(['sync_status' => 'failed', 'sync_error' => 'Incoming mail sync failed. Check the IMAP host, TLS port, username, and password.']);
            throw $exception;
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        Mailbox::whereKey($this->mailboxId)->update(['sync_status' => 'failed', 'sync_error' => 'Incoming mail sync failed. Check the mailbox settings and queue worker.']);
    }
}
