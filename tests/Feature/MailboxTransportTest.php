<?php

namespace Tests\Feature;

use App\Models\Mailbox;
use App\Services\MailboxConnections;
use App\Services\SmtpConnection;
use Mockery;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\Stream\AbstractStream;
use Tests\TestCase;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;

class MailboxTransportTest extends TestCase
{
    private function mailbox(): Mailbox
    {
        return Mailbox::factory()->make(['smtp_host' => 'smtp.example.com', 'smtp_port' => 465, 'smtp_encryption' => 'ssl', 'smtp_username' => 'support', 'smtp_password' => 'secret',
            'imap_host' => 'imap.example.com', 'imap_port' => 993, 'imap_encryption' => 'ssl', 'imap_username' => 'support', 'imap_password' => 'secret']);
    }

    public function test_smtp_uses_verified_tls_and_a_bounded_timeout(): void
    {
        $this->assertInstanceOf(MailboxConnections::class, app(MailboxConnections::class));
        $transport = app(SmtpConnection::class, ['mailbox' => $this->mailbox()]);
        $this->assertTrue($transport->isTlsRequired());
        $this->assertTrue($transport->getStream()->isTLS());
        $this->assertSame(10.0, $transport->getStream()->getTimeout());
        $this->assertSame(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]], $transport->getStream()->getStreamOptions());
        $mailbox = $this->mailbox();
        $mailbox->smtp_encryption = 'tls';
        $mailbox->smtp_port = 587;
        $startTls = new SmtpConnection($mailbox);
        $this->assertFalse($startTls->getStream()->isTLS());
        $this->assertTrue($startTls->isAutoTls());
        $this->assertTrue($startTls->isTlsRequired());
    }

    private function stream(array $responses, array &$commands): AbstractStream
    {
        $stream = Mockery::mock(AbstractStream::class);
        $stream->shouldReceive('setHost', 'setPort', 'setTimeout', 'setStreamOptions')->andReturnSelf();
        $stream->shouldReceive('isTLS')->andReturnTrue();
        $stream->shouldReceive('initialize')->once();
        $stream->shouldReceive('readLine')->andReturnUsing(function () use (&$responses): string {
            return array_shift($responses) ?? '';
        });
        $stream->shouldReceive('write')->andReturnUsing(function (string $command) use (&$commands): void {
            $commands[] = $command;
        });
        $stream->shouldReceive('terminate')->atLeast()->once();

        return $stream;
    }

    public function test_smtp_authenticates_then_quits_without_sending_any_message(): void
    {
        $commands = [];
        $stream = $this->stream(["220 Ready\r\n", "250-test.example.com\r\n", "250 AUTH LOGIN\r\n", "334 Username\r\n", "334 Password\r\n", "235 Authenticated\r\n", "221 Bye\r\n"], $commands);
        $transport = new SmtpConnection($this->mailbox(), $stream);
        $transport->start();
        $transport->close();
        $this->assertSame(["EHLO [127.0.0.1]\r\n", "AUTH LOGIN\r\n", base64_encode('support')."\r\n", base64_encode('secret')."\r\n", "QUIT\r\n"], $commands);
    }

    public function test_smtp_does_not_report_credentials_valid_when_authentication_is_unavailable(): void
    {
        $commands = [];
        $stream = $this->stream(["220 Ready\r\n", "250-test.example.com\r\n", "250 SIZE 1000\r\n", "221 Bye\r\n"], $commands);
        $transport = new SmtpConnection($this->mailbox(), $stream);
        try {
            $this->expectException(TransportException::class);
            $this->expectExceptionMessage('does not support authentication');
            $transport->start();
        } finally {
            $transport->close();
        }
    }

    public function test_failed_smtp_is_closed_and_imap_login_is_still_tested_without_reading_mail(): void
    {
        $transport = Mockery::mock(SmtpConnection::class);
        $transport->shouldReceive('start')->once()->andThrow(new RuntimeException('Connection timed out with sensitive credentials'));
        $transport->shouldReceive('close')->once();
        $transport->shouldReceive('stop')->zeroOrMoreTimes();
        $transport->shouldNotReceive('send');
        $this->app->bind(SmtpConnection::class, fn () => $transport);
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('connect')->once()->andReturnSelf();
        $client->shouldReceive('disconnect')->once()->andReturnSelf();
        $client->shouldNotReceive('getFolder', 'getFolders');
        $clients = Mockery::mock(ClientManager::class);
        $clients->shouldReceive('make')->once()->with(Mockery::on(fn (array $config) => $config['timeout'] === 10 && $config['validate_cert'] === true && $config['encryption'] === 'ssl' && $config['username'] === 'support' && $config['password'] === 'secret'))->andReturn($client);
        $result = (new MailboxConnections($clients))->test($this->mailbox(), 1);
        $this->assertFalse($result['smtp']['success']);
        $this->assertTrue($result['imap']['success']);
        $this->assertNull($result['token']);
        $this->assertStringNotContainsString('sensitive', $result['smtp']['message']);
    }

    public function test_imap_authentication_failure_is_disconnected_and_never_approved(): void
    {
        $transport = Mockery::mock(SmtpConnection::class);
        $transport->shouldReceive('start', 'close')->once();
        $transport->shouldReceive('stop')->zeroOrMoreTimes();
        $this->app->bind(SmtpConnection::class, fn () => $transport);
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('connect')->once()->andThrow(new RuntimeException('Authentication failed for secret-user'));
        $client->shouldReceive('disconnect')->once()->andReturnSelf();
        $clients = Mockery::mock(ClientManager::class);
        $clients->shouldReceive('make')->once()->andReturn($client);
        $result = (new MailboxConnections($clients))->test($this->mailbox(), 1);
        $this->assertTrue($result['smtp']['success']);
        $this->assertFalse($result['imap']['success']);
        $this->assertNull($result['token']);
        $this->assertStringNotContainsString('secret-user', $result['imap']['message']);
    }
}
