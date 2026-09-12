<?php

namespace App\Services;

use App\Models\Mailbox;
use Illuminate\Support\Facades\DB;
use Webklex\PHPIMAP\Message;
use Webklex\PHPIMAP\Part;

class DeliveryReport
{
    public function isReport(Message $mail): bool
    {
        return str_starts_with(strtolower((string) $mail->get('content_type')), 'multipart/report');
    }

    /** Process structured DSNs only, correlated to an actual outgoing attempt in this mailbox. */
    public function consume(Mailbox $mailbox, Message $mail): bool
    {
        if (! $this->isReport($mail)) {
            return false;
        }
        $ids = [];
        $failure = null;
        $hasDeliveryStatus = false;
        $events = [];
        foreach ($mail->getStructure()?->parts ?? [] as $part) {
            $type = strtolower($part->content_type ?? '');
            $content = $this->decode($part);
            if (in_array($type, ['message/delivery-status', 'message/global-delivery-status'])) {
                $hasDeliveryStatus = true;
                $ids = [...$ids, ...$this->messageIds($content)];
                foreach (preg_split('/\r?\n\r?\n/', $content) as $block) {
                    $fields = $this->fields($block);
                    $recipient = trim(explode(';', $fields['final-recipient'] ?? $fields['original-recipient'] ?? '', 2)[1] ?? '');
                    if (strtolower($fields['action'] ?? '') === 'delivered' && str_starts_with($fields['status'] ?? '', '2.')) {
                        $events[] = ['type' => 'delivered', 'recipient' => $recipient, 'detail' => 'Delivery confirmed by the receiving server.'];
                    }
                    if (strtolower($fields['action'] ?? '') === 'failed' && preg_match('/^[45]\.\d{1,3}\.\d{1,3}\\b/', $fields['status'] ?? '', $status)) {
                        $failure = mb_substr('Bounce '.$status[0].': '.($fields['diagnostic-code'] ?? 'The receiving server rejected this email.'), 0, 250);
                        $events[] = ['type' => 'failed', 'recipient' => $recipient, 'detail' => $failure];
                    }
                }
            } elseif ($type === 'message/feedback-report') {
                $hasDeliveryStatus = true;
                $fields = $this->fields($content);
                if (in_array(strtolower($fields['feedback-type'] ?? ''), ['abuse', 'fraud'])) {
                    $recipient = trim($fields['original-rcpt-to'] ?? '', '<> ');
                    $events[] = ['type' => 'complaint', 'recipient' => $recipient, 'detail' => 'Abuse feedback report received.'];
                }
                $ids = [...$ids, ...$this->messageIds($content)];
            } elseif (in_array($type, ['message/rfc822', 'message/global', 'text/rfc822-headers', 'message/global-headers'])) {
                $ids = [...$ids, ...$this->messageIds(preg_split('/\r?\n\r?\n/', $content, 2)[0])];
            }
        }
        if (! $hasDeliveryStatus) {
            return false;
        }
        if ($ids === []) {
            foreach (['original_message_id', 'x_original_message_id', 'in_reply_to', 'references'] as $header) {
                preg_match_all('/<([^<>\s]+)>/', (string) $mail->get($header), $matches);
                if ($matches[1] !== []) {
                    $ids = [end($matches[1])];
                    break;
                }
            }
        }
        foreach (array_slice(array_unique($ids), -30) as $id) {
            $attempt = DB::table('mail_delivery_attempts')->where('mailbox_id', $mailbox->id)->where('external_id', $id)->first();
            if ($attempt) {
                $recipients = array_map('mb_strtolower', json_decode($attempt->recipients ?? 'null', true) ?? [$attempt->recipient]);
                foreach ($events as $event) {
                    if ($event['recipient'] && in_array(mb_strtolower($event['recipient']), $recipients, true)) {
                        app(DeliveryTracking::class)->record($attempt->id, $event['type'], 'dsn:'.hash('sha256', $attempt->id.json_encode($event)), $event['recipient'], $event['detail']);
                    }
                }
                if ($failure !== null) {
                    app(MailSafety::class)->recordFailure($attempt->id, 'bounce', $failure);
                }

                return true;
            }
        }

        return false;
    }

    private function fields(string $text): array
    {
        $text = preg_replace('/\r?\n[\t ]+/', ' ', $text);
        preg_match_all('/^([A-Za-z-]+):[\t ]*([^\r\n]*)/m', $text, $matches, PREG_SET_ORDER);
        $fields = [];
        foreach ($matches as $match) {
            $fields[strtolower($match[1])] = trim($match[2]);
        }

        return $fields;
    }

    private function messageIds(string $text): array
    {
        $fields = $this->fields($text);
        $ids = [];
        foreach (['message-id', 'original-message-id', 'x-original-message-id'] as $key) {
            if (! empty($fields[$key])) {
                $ids[] = trim($fields[$key], '<> ');
            }
        }

        return $ids;
    }

    private function decode(Part $part): string
    {
        return match (strtolower((string) $part->getHeader()?->get('content_transfer_encoding'))) {
            'base64' => base64_decode($part->content, true) ?: '',
            'quoted-printable' => quoted_printable_decode($part->content),
            default => $part->content,
        };
    }
}
