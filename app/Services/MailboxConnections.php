<?php

namespace App\Services;

use App\Models\Mailbox;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;
use Webklex\PHPIMAP\ClientManager;

class MailboxConnections
{
    public function __construct(private ClientManager $clients) {}

    /** @return array<string, int|string> */
    public function settings(Mailbox $mailbox): array
    {
        $settings = [];
        foreach (['smtp', 'imap'] as $protocol) {
            foreach (['host', 'port', 'encryption', 'username', 'password'] as $field) {
                $key = $protocol.'_'.$field;
                $settings[$key] = $field === 'port' ? (int) $mailbox->$key : (string) ($mailbox->$key ?? '');
            }
        }

        return $settings;
    }

    /** @return array{smtp: array{success: bool, message: string}, imap: array{success: bool, message: string}, token: ?string, expires_at: ?string} */
    public function test(Mailbox $mailbox, int $userId): array
    {
        Cache::forget($this->cacheKey($mailbox, $userId));
        $results = [];
        foreach (['smtp', 'imap'] as $protocol) {
            try {
                $this->$protocol($mailbox);
                $results[$protocol] = ['success' => true, 'message' => strtoupper($protocol).' connection successful.'];
            } catch (Throwable $exception) {
                $results[$protocol] = ['success' => false, 'message' => $this->failureMessage($protocol, $exception)];
            }
        }
        $token = null;
        $expires = null;
        if ($results['smtp']['success'] && $results['imap']['success']) {
            $token = Str::random(64);
            $expires = now()->addMinutes(10);
            Cache::put($this->cacheKey($mailbox, $userId), [
                'fingerprint' => $this->fingerprint($mailbox), 'token_hash' => hash('sha256', $token),
            ], $expires);
        }

        return [...$results, 'token' => $token, 'expires_at' => $expires?->toIso8601String()];
    }

    public function assertVerified(Mailbox $mailbox, int $userId, ?string $token): void
    {
        $proof = Cache::get($this->cacheKey($mailbox, $userId));
        if (! $token || ! $proof || ! hash_equals($proof['token_hash'], hash('sha256', $token)) || ! hash_equals($proof['fingerprint'], $this->fingerprint($mailbox))) {
            throw ValidationException::withMessages(['connection_token' => 'Test both SMTP and IMAP successfully with the current settings before saving. Results expire after 10 minutes.']);
        }
    }

    public function forget(Mailbox $mailbox, int $userId): void
    {
        Cache::forget($this->cacheKey($mailbox, $userId));
    }

    protected function smtp(Mailbox $mailbox): void
    {
        $transport = app(SmtpConnection::class, ['mailbox' => $mailbox]);
        try {
            $transport->start();
        } finally {
            try {
                $transport->close();
            } catch (Throwable) {
            }
        }
    }

    protected function imap(Mailbox $mailbox): void
    {
        $client = $this->clients->make([
            'host' => $mailbox->imap_host, 'port' => (int) $mailbox->imap_port, 'encryption' => $mailbox->imap_encryption,
            'validate_cert' => true, 'username' => $mailbox->imap_username, 'password' => $mailbox->imap_password,
            'protocol' => 'imap', 'timeout' => 10,
        ]);
        try {
            $client->connect();
        } finally {
            try {
                $client->disconnect();
            } catch (Throwable) {
            }
        }
    }

    private function cacheKey(Mailbox $mailbox, int $userId): string
    {
        return 'mailbox-connection:'.$userId.':'.($mailbox->getKey() ?? 'new');
    }

    private function fingerprint(Mailbox $mailbox): string
    {
        return hash_hmac('sha256', json_encode($this->settings($mailbox), JSON_THROW_ON_ERROR), config('app.key'));
    }

    private function failureMessage(string $protocol, Throwable $exception): string
    {
        $details = '';
        do {
            $details .= ' '.strtolower($exception->getMessage());
        } while ($exception = $exception->getPrevious());

        $message = match (true) {
            Str::contains($details, ['certificate', 'ssl', 'tls']) => 'Secure connection failed. Check the encryption setting, port, and server certificate.',
            Str::contains($details, ['authenticate', 'authentication', 'login', '535', '534']) => 'Login failed. Check the username and password; your provider may require an app password.',
            Str::contains($details, ['timed out', 'timeout']) => 'Connection timed out. Check the host, port, and firewall access.',
            Str::contains($details, ['getaddrinfo', 'php_network_getaddresses', 'name or service', 'nodename']) => 'Server hostname could not be resolved. Check the host address.',
            default => 'Connection failed. Check the host, port, encryption, and credentials.',
        };

        return strtoupper($protocol).': '.$message;
    }
}
