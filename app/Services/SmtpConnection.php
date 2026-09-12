<?php

namespace App\Services;

use App\Models\Mailbox;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\AbstractStream;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

class SmtpConnection extends EsmtpTransport
{
    public function __construct(Mailbox $mailbox, ?AbstractStream $stream = null)
    {
        parent::__construct($mailbox->smtp_host, (int) $mailbox->smtp_port, $mailbox->smtp_encryption === 'ssl', stream: $stream);
        $this->setUsername($mailbox->smtp_username ?? '');
        $this->setPassword($mailbox->smtp_password ?? '');
        $this->setRequireTls(true);
        /** @var SocketStream $stream */
        $stream = $this->getStream();
        $stream->setTimeout(10);
        $stream->setStreamOptions(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]]);
    }

    public function start(): void
    {
        parent::start();
        $capabilities = $this->getCapabilities();
        if ($this->getUsername() !== '' && ! array_key_exists('AUTH', $capabilities)) {
            throw new TransportException('The SMTP server does not support authentication.');
        }
    }

    public function close(): void
    {
        try {
            $this->stop();
        } finally {
            $this->getStream()->terminate();
        }
    }
}
