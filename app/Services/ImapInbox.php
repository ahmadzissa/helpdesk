<?php

namespace App\Services;

use App\Models\Mailbox;
use Carbon\CarbonImmutable;
use RuntimeException;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Message;

class ImapInbox
{
    public function __construct(private ClientManager $clients) {}

    public function findMessage(Mailbox $mailbox, string $externalId): ?Message
    {
        if ($externalId === '' || preg_match('/[\r\n\x00]/', $externalId)) {
            return null;
        }
        $client = $this->clients->make([
            'host' => $mailbox->imap_host, 'port' => $mailbox->imap_port, 'encryption' => $mailbox->imap_encryption,
            'validate_cert' => true, 'username' => $mailbox->imap_username, 'password' => $mailbox->imap_password,
            'protocol' => 'imap', 'timeout' => 30,
        ]);
        try {
            $client->connect();
            $protocol = $client->getConnection();
            $protocol->examineFolder('INBOX')->validatedData();
            $uids = $protocol->search(['HEADER Message-ID "'.addcslashes($externalId, '\\"').'"'])->validatedData();
            foreach (array_slice($uids, 0, 5) as $uid) {
                $content = $protocol->fetch(['UID', 'BODY.PEEK[]'], [(int) $uid])->validatedData();
                $raw = $content[(int) $uid]['BODY[]'] ?? null;
                if (is_string($raw) && $raw !== '') {
                    $message = Message::fromString($raw, $client->getConfig());
                    if (trim((string) $message->get('message_id'), '<> ') === $externalId) {
                        return $message;
                    }
                }
            }

            return null;
        } finally {
            $client->disconnect();
        }
    }

    /** @param callable(Message): void $consume */
    public function receive(Mailbox $mailbox, callable $consume): void
    {
        $client = $this->clients->make([
            'host' => $mailbox->imap_host, 'port' => $mailbox->imap_port, 'encryption' => $mailbox->imap_encryption,
            'validate_cert' => true, 'username' => $mailbox->imap_username, 'password' => $mailbox->imap_password,
            'protocol' => 'imap', 'timeout' => 30,
        ]);
        try {
            $client->connect();
            $protocol = $client->getConnection();
            $status = $protocol->examineFolder('INBOX')->validatedData();
            $validity = (int) ($status['uidvalidity'] ?? 0);
            $ceiling = (int) ($status['uidnext'] ?? 0) - 1;
            if ($validity < 1 || $ceiling < 0) {
                throw new RuntimeException('The IMAP server did not return a valid INBOX cursor.');
            }
            if ($mailbox->imap_uidvalidity !== $validity) {
                $mailbox->forceFill(['imap_uidvalidity' => $validity, 'imap_last_uid' => 0])->save();
            }
            $lastUid = (int) $mailbox->imap_last_uid;
            if ($lastUid >= $ceiling) {
                return;
            }
            $cutoff = CarbonImmutable::instance($mailbox->import_started_at ?? $mailbox->created_at)->utc();
            $since = $cutoff->subDay()->format('d-M-Y');
            $search = 'UID '.($lastUid + 1).':'.$ceiling.' SINCE "'.$since.'"';
            $uids = array_values(array_unique(array_filter(array_map('intval', $protocol->search([$search])->validatedData()),
                fn (int $uid) => $uid > $lastUid && $uid <= $ceiling)));
            sort($uids, SORT_NUMERIC);
            foreach (array_chunk($uids, 25) as $batch) {
                $dates = [];
                $responses = $protocol->requestAndResponse('UID FETCH', [implode(',', $batch), '(UID INTERNALDATE)'], true)->validatedData();
                foreach ($responses as $response) {
                    if (is_string($response) && preg_match('/^\d+ FETCH \(.*?\bUID (\d+)\b/i', $response, $uidMatch)) {
                        $dates[(int) $uidMatch[1]] = ['INTERNALDATE' => preg_match('/\bINTERNALDATE "( ?\d{1,2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2} [+-]\d{4})"/i', $response, $dateMatch) ? $dateMatch[1] : null];
                    }
                }
                foreach ($batch as $uid) {
                    if (isset($dates[$uid])) {
                        $internalDate = $dates[$uid]['INTERNALDATE'] ?? null;
                        if (! is_string($internalDate) || trim($internalDate) === '') {
                            throw new RuntimeException('The IMAP server did not return the received time.');
                        }
                        $receivedAt = CarbonImmutable::parse($internalDate)->utc();
                        if ($receivedAt->greaterThanOrEqualTo($cutoff)) {
                            $content = $protocol->fetch(['UID', 'BODY.PEEK[]'], [$uid])->validatedData();
                            if (isset($content[$uid])) {
                                $raw = $content[$uid]['BODY[]'] ?? null;
                                if (! is_string($raw) || $raw === '') {
                                    throw new RuntimeException('The IMAP server did not return the new message content.');
                                }
                                $message = Message::fromString($raw, $client->getConfig());
                                $message->getHeader()->set('relay_import_fingerprint', hash('sha256', $raw).'@relay.import');
                                $consume($message);
                            }
                        }
                    }
                    $mailbox->forceFill(['imap_last_uid' => $uid])->save();
                }
            }
            $mailbox->forceFill(['imap_last_uid' => $ceiling])->save();
        } finally {
            $client->disconnect();
        }
    }
}
