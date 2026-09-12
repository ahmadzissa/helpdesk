<?php

namespace Tests\Feature;

use App\Jobs\SyncMailbox;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Ticket;
use App\Services\ImapInbox;
use App\Services\IncomingMail;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\Response;

class IncrementalMailSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-11 10:00:00 UTC'));
        Storage::fake('local');
    }

    private function mailbox(): Mailbox
    {
        return Mailbox::factory()->create(['email' => 'support@example.com', 'incoming_enabled' => true,
            'imap_host' => 'imap.example.com', 'imap_port' => 993, 'imap_encryption' => 'ssl',
            'imap_username' => 'support@example.com', 'imap_password' => 'secret']);
    }

    private function response(array $data): Response
    {
        return Response::empty()->setResult($data)->setCanBeEmpty(true);
    }

    /** @return array{ImapInbox, ImapProtocol} */
    private function inbox(int $nextUid, int $validity = 500, ?ImapProtocol $wireProtocol = null): array
    {
        $protocol = $wireProtocol ?? Mockery::mock(ImapProtocol::class);
        if (! $wireProtocol) {
            $protocol->shouldReceive('examineFolder')->once()->with('INBOX')->andReturn($this->response(['uidvalidity' => $validity, 'uidnext' => $nextUid]));
        }
        $protocol->shouldNotReceive('selectFolder', 'store', 'expunge', 'deleteFolder', 'moveMessage', 'copyMessage');
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('connect')->once()->andReturnSelf();
        $client->shouldReceive('getConnection')->once()->andReturn($protocol);
        $client->shouldReceive('getConfig')->andReturn((new ClientManager)->getConfig());
        $client->shouldReceive('disconnect')->once()->andReturnSelf();
        $client->shouldNotReceive('openFolder', 'getFolder');
        $clients = Mockery::mock(ClientManager::class);
        $clients->shouldReceive('make')->once()->with(Mockery::on(fn (array $config) => $config['validate_cert'] === true && $config['username'] === 'support@example.com' && $config['password'] === 'secret'))->andReturn($client);

        return [new ImapInbox($clients), $protocol];
    }

    /** @param array<int, string|null> $dates */
    private function expectDates(ImapProtocol $protocol, array $uids, array $dates): void
    {
        $responses = [];
        foreach ($dates as $uid => $date) {
            $responses[] = '1 FETCH (UID '.$uid.($date === null ? '' : ' INTERNALDATE "'.$date.'"').")\r\n";
        }
        $responses[] = "OK Fetch completed\r\n";
        $protocol->shouldReceive('requestAndResponse')->once()->with('UID FETCH', [implode(',', $uids), '(UID INTERNALDATE)'], true)->andReturn($this->response($responses));
    }

    private function mail(string $id = 'new@example.com', string $subject = 'New support request'): string
    {
        return "From: Customer <customer@example.com>\r\nTo: support@example.com\r\nMessage-ID: <{$id}>\r\nDate: Mon, 01 Jan 2001 00:00:00 +0000\r\nSubject: {$subject}\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nPlease help with my order.\r\n";
    }

    private function runSync(Mailbox $mailbox, ImapInbox $inbox): void
    {
        (new SyncMailbox($mailbox->id))->handle(app(IncomingMail::class), $inbox);
    }

    public function test_format_recovery_reads_exact_existing_message_without_advancing_import_cursor(): void
    {
        $mailbox = $this->mailbox();
        $before = $mailbox->fresh()->getAttributes();
        [$inbox, $protocol] = $this->inbox(105);
        $protocol->shouldReceive('search')->once()->with(['HEADER Message-ID "old@example.com"'])->andReturn($this->response([2, 3]));
        $protocol->shouldReceive('fetch')->once()->with(['UID', 'BODY.PEEK[]'], [2])->andReturn($this->response([2 => ['BODY[]' => $this->mail('other@example.com')]]));
        $protocol->shouldReceive('fetch')->once()->with(['UID', 'BODY.PEEK[]'], [3])->andReturn($this->response([3 => ['BODY[]' => $this->mail('old@example.com')]]));
        $found = $inbox->findMessage($mailbox, 'old@example.com');
        $this->assertSame('old@example.com', trim((string) $found->get('message_id'), '<> '));
        $this->assertSame($before, $mailbox->fresh()->getAttributes());
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_first_sync_only_fetches_bodies_received_since_account_creation_and_preserves_remote_state(): void
    {
        $mailbox = $this->mailbox();
        $this->travel(2)->hours();
        [$inbox, $protocol] = $this->inbox(105);
        $protocol->shouldReceive('search')->once()->with(['UID 1:104 SINCE "10-Sep-2026"'])->andReturn($this->response([104, 102, 103]));
        $this->expectDates($protocol, [102, 103, 104], [102 => '11-Sep-2026 09:59:59 +0000', 103 => '11-Sep-2026 04:00:00 -0600', 104 => '11-Sep-2026 13:01:00 +0300']);
        foreach ([103, 104] as $uid) {
            $protocol->shouldReceive('fetch')->once()->with(['UID', 'BODY.PEEK[]'], [$uid])->andReturn($this->response([$uid => ['UID' => $uid, 'BODY[]' => $this->mail($uid.'@example.com')]]));
        }
        $this->runSync($mailbox, $inbox);
        $this->assertDatabaseCount('tickets', 2);
        $this->assertDatabaseCount('mail_import_receipts', 2);
        $this->assertDatabaseHas('mailboxes', ['id' => $mailbox->id, 'imap_last_uid' => 104, 'imap_uidvalidity' => 500, 'sync_status' => 'idle', 'import_started_at' => '2026-09-11 10:00:00']);
        $this->assertDatabaseHas('messages', ['external_id' => '103@example.com', 'body' => 'Please help with my order.']);

        [$unchanged, $unchangedProtocol] = $this->inbox(105);
        $unchangedProtocol->shouldNotReceive('search', 'fetch');
        $this->runSync($mailbox, $unchanged);
        $this->assertDatabaseCount('messages', 2);
    }

    public function test_later_sync_fetches_only_new_uids_and_does_not_miss_mail_arriving_between_polls(): void
    {
        $mailbox = $this->mailbox();
        $mailbox->forceFill(['imap_uidvalidity' => 500, 'imap_last_uid' => 104])->save();
        [$inbox, $protocol] = $this->inbox(108);
        $protocol->shouldReceive('search')->once()->with(['UID 105:107 SINCE "10-Sep-2026"'])->andReturn($this->response([106, 107]));
        $this->expectDates($protocol, [106, 107], [106 => '11-Sep-2026 10:01:00 +0000']);
        $protocol->shouldReceive('fetch')->once()->with(['UID', 'BODY.PEEK[]'], [106])->andReturn($this->response([106 => ['UID' => 106, 'BODY[]' => $this->mail()]]));
        $this->runSync($mailbox, $inbox);
        $this->assertSame(107, $mailbox->fresh()->imap_last_uid);
        $this->assertDatabaseCount('tickets', 1);
    }

    public function test_empty_inbox_and_search_results_advance_safely_without_fetching_old_mail(): void
    {
        $mailbox = $this->mailbox();
        [$empty, $emptyProtocol] = $this->inbox(1);
        $emptyProtocol->shouldNotReceive('search', 'fetch');
        $this->runSync($mailbox, $empty);
        [$oldOnly, $oldProtocol] = $this->inbox(1001);
        $oldProtocol->shouldReceive('search')->once()->with(['UID 1:1000 SINCE "10-Sep-2026"'])->andReturn($this->response([]));
        $oldProtocol->shouldNotReceive('fetch');
        $this->runSync($mailbox, $oldOnly);
        $this->assertSame(1000, $mailbox->fresh()->imap_last_uid);
        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_failed_fetch_retries_from_the_last_successful_email_without_duplicates(): void
    {
        $mailbox = $this->mailbox();
        [$inbox, $protocol] = $this->inbox(3);
        $protocol->shouldReceive('search')->once()->andReturn($this->response([1, 2]));
        $this->expectDates($protocol, [1, 2], [1 => '11-Sep-2026 10:01:00 +0000', 2 => '11-Sep-2026 10:02:00 +0000']);
        $protocol->shouldReceive('fetch')->once()->with(['UID', 'BODY.PEEK[]'], [1])->andReturn($this->response([1 => ['BODY[]' => $this->mail()]]));
        $protocol->shouldReceive('fetch')->once()->with(['UID', 'BODY.PEEK[]'], [2])->andThrow(new RuntimeException('Connection lost'));
        try {
            $this->runSync($mailbox, $inbox);
            $this->fail('The failed fetch should be retried later.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Connection lost', $exception->getMessage());
        }
        $this->assertSame(1, $mailbox->fresh()->imap_last_uid);
        $this->assertSame('failed', $mailbox->fresh()->sync_status);
        $this->assertDatabaseCount('tickets', 1);

        [$retry, $retryProtocol] = $this->inbox(3);
        $retryProtocol->shouldReceive('search')->once()->with(['UID 2:2 SINCE "10-Sep-2026"'])->andReturn($this->response([2]));
        $this->expectDates($retryProtocol, [2], [2 => '11-Sep-2026 10:02:00 +0000']);
        $retryProtocol->shouldReceive('fetch')->once()->with(['UID', 'BODY.PEEK[]'], [2])->andReturn($this->response([2 => ['BODY[]' => $this->mail('second@example.com')]]));
        $this->runSync($mailbox, $retry);
        $this->assertDatabaseCount('tickets', 2);
        $this->assertDatabaseCount('mail_import_receipts', 2);
        $this->assertSame(2, $mailbox->fresh()->imap_last_uid);
    }

    public function test_missing_received_time_stops_sync_without_importing_or_advancing_past_the_message(): void
    {
        $mailbox = $this->mailbox();
        [$inbox, $protocol] = $this->inbox(2);
        $protocol->shouldReceive('search')->once()->andReturn($this->response([1]));
        $this->expectDates($protocol, [1], [1 => null]);
        try {
            $this->runSync($mailbox, $inbox);
            $this->fail('A received timestamp is required.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('received time', $exception->getMessage());
        }
        $this->assertSame(0, $mailbox->fresh()->imap_last_uid);
        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_uid_reset_and_missing_message_id_cannot_recreate_a_permanently_deleted_ticket(): void
    {
        $mailbox = $this->mailbox();
        $raw = preg_replace('/^(Message-ID|Date):[^\r\n]*\r\n/m', '', $this->mail());
        foreach ([500, 600] as $validity) {
            [$inbox, $protocol] = $this->inbox(2, $validity);
            $protocol->shouldReceive('search')->once()->with(['UID 1:1 SINCE "10-Sep-2026"'])->andReturn($this->response([1]));
            $this->expectDates($protocol, [1], [1 => '11-Sep-2026 10:01:00 +0000']);
            $protocol->shouldReceive('fetch')->once()->with(['UID', 'BODY.PEEK[]'], [1])->andReturn($this->response([1 => ['BODY[]' => $raw]]));
            $this->runSync($mailbox, $inbox);
            if ($validity === 500) {
                $this->assertDatabaseCount('tickets', 1);
                Ticket::first()->delete();
                $this->travel(2)->hours();
            }
        }
        $this->assertDatabaseCount('mail_import_receipts', 1);
        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('messages', 0);
        $this->assertSame(600, $mailbox->fresh()->imap_uidvalidity);
    }

    public function test_new_mime_messages_still_import_their_body_and_attachments(): void
    {
        $mailbox = $this->mailbox();
        [$inbox, $protocol] = $this->inbox(2);
        $raw = "From: Customer <customer@example.com>\r\nTo: support@example.com\r\nMessage-ID: <attachment@example.com>\r\nSubject: Receipt attached\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=boundary\r\n\r\n--boundary\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nMy receipt is attached.\r\n--boundary\r\nContent-Type: text/plain; name=receipt.txt\r\nContent-Disposition: attachment; filename=receipt.txt\r\nContent-Transfer-Encoding: base64\r\n\r\n".base64_encode('Receipt #123')."\r\n--boundary--\r\n";
        $protocol->shouldReceive('search')->once()->andReturn($this->response([1]));
        $this->expectDates($protocol, [1], [1 => '11-Sep-2026 10:01:00 +0000']);
        $protocol->shouldReceive('fetch')->once()->with(['UID', 'BODY.PEEK[]'], [1])->andReturn($this->response([1 => ['BODY[]' => $raw]]));
        $this->runSync($mailbox, $inbox);
        $message = Message::firstOrFail();
        $this->assertStringContainsString('My receipt is attached.', $message->body);
        $this->assertCount(1, $message->attachments);
        $this->assertSame('receipt.txt', $message->attachments[0]['name']);
        $this->assertSame('Receipt #123', Storage::disk('local')->get($message->attachments[0]['path']));
    }

    public function test_overlapping_syncs_and_disabled_accounts_do_not_connect(): void
    {
        $mailbox = $this->mailbox();
        $inbox = Mockery::mock(ImapInbox::class);
        $inbox->shouldNotReceive('receive');
        $lock = Cache::lock('imap-import-'.$mailbox->id, 950);
        $this->assertTrue($lock->get());
        try {
            $this->runSync($mailbox, $inbox);
        } finally {
            $lock->release();
        }
        $mailbox->update(['incoming_enabled' => false]);
        $this->runSync($mailbox, $inbox);

        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_real_protocol_parses_quoted_received_dates_and_raw_message_literals(): void
    {
        $mailbox = $this->mailbox();
        $raw = $this->mail();
        $lines = [
            "* 2 EXISTS\r\n", "* OK [UIDVALIDITY 500] Validity\r\n", "* OK [UIDNEXT 3] Next UID\r\n", "TAG1 OK [READ-ONLY] Examine completed\r\n",
            "* SEARCH 1 2\r\n", "TAG2 OK Search completed\r\n",
            "* 1 FETCH (UID 1 INTERNALDATE \"11-Sep-2026 09:59:59 +0000\")\r\n",
            "* 2 FETCH (INTERNALDATE \"11-Sep-2026 10:01:00 +0000\" UID 2)\r\n", "TAG3 OK Fetch completed\r\n",
            '* 2 FETCH (UID 2 BODY[] {'.strlen($raw)."}\r\n", $raw, ")\r\n", "TAG4 OK Fetch completed\r\n",
        ];
        $commands = [];
        $protocol = Mockery::mock(ImapProtocol::class.'[nextLine,write]', [(new ClientManager)->getConfig()]);
        $protocol->shouldReceive('nextLine')->andReturnUsing(function () use (&$lines): string {
            return array_shift($lines) ?? throw new RuntimeException('Unexpected read from IMAP');
        });
        $protocol->shouldReceive('write')->andReturnUsing(function (Response $response, string $command) use (&$commands): void {
            $commands[] = $command;
        });
        [$inbox] = $this->inbox(3, wireProtocol: $protocol);
        $this->runSync($mailbox, $inbox);
        $this->assertSame(['TAG1 EXAMINE "INBOX"', 'TAG2 UID SEARCH UID 1:2 SINCE "10-Sep-2026"', 'TAG3 UID FETCH 1,2 (UID INTERNALDATE)', 'TAG4 UID FETCH 2:2 (UID BODY.PEEK[])'], $commands);
        $this->assertSame([], $lines);
        $this->assertDatabaseCount('tickets', 1);
        $this->assertDatabaseHas('messages', ['external_id' => 'new@example.com', 'body' => 'Please help with my order.']);
        $this->assertSame(2, $mailbox->fresh()->imap_last_uid);
    }
}
