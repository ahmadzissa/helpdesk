<?php

namespace Tests\Feature;

use App\Models\Mailbox;
use App\Models\User;
use App\Services\ImapInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;
use Webklex\PHPIMAP\Message as ImapMessage;

class LocalMailPollingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['env'] = 'local';
        config(['queue.default' => 'database']);
        $this->travelTo(now()->startOfSecond());
        $this->withSession(['_token' => 'local-poll-test'])->withHeader('X-CSRF-TOKEN', 'local-poll-test');
    }

    public function test_local_polling_imports_new_mail_without_a_worker_and_never_duplicates_it(): void
    {
        $this->actingAs(User::factory()->create());
        $mailbox = Mailbox::factory()->create(['incoming_enabled' => true]);
        Mailbox::factory()->create(['incoming_enabled' => false]);
        $this->mock(ImapInbox::class)->shouldReceive('receive')->twice()->andReturnUsing(function (Mailbox $box, callable $consume) use ($mailbox): void {
            $this->assertSame($mailbox->id, $box->id);
            $consume(ImapMessage::fromString("From: Customer <customer@example.com>\r\nTo: support@example.com\r\nMessage-ID: <local-poll@example.com>\r\nSubject: New local message\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nPlease help with my order."));
        });

        $this->postJson('/api/v1/mailboxes/poll')->assertOk()->assertJsonPath('checked', 1)->assertJsonPath('errors', []);
        $this->assertDatabaseHas('tickets', ['subject' => 'New local message']);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('messages', 1);
        $this->postJson('/api/v1/mailboxes/poll')->assertOk()->assertJsonPath('checked', 0);
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/mailboxes/poll')->assertOk()->assertJsonPath('checked', 1);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('mail_import_receipts', 1);
        $this->assertSame('idle', $mailbox->fresh()->sync_status);
    }

    public function test_polling_is_authenticated_and_is_only_enabled_for_local_installations(): void
    {
        $this->mock(ImapInbox::class)->shouldNotReceive('receive');
        Mailbox::factory()->create(['incoming_enabled' => true]);
        $this->postJson('/api/v1/mailboxes/poll')->assertUnauthorized();
        $this->actingAs(User::factory()->create());
        $this->getJson('/api/v1/workspace')->assertOk()->assertJsonPath('local_mail_polling', true);
        $this->app['env'] = 'production';
        $this->getJson('/api/v1/workspace')->assertOk()->assertJsonPath('local_mail_polling', false);
        $this->postJson('/api/v1/mailboxes/poll')->assertNotFound();
    }

    public function test_failed_connections_are_rate_limited_and_do_not_block_other_accounts_or_expose_errors(): void
    {
        $this->actingAs(User::factory()->create());
        $failed = Mailbox::factory()->create(['incoming_enabled' => true, 'name' => 'Broken account']);
        $healthy = Mailbox::factory()->create(['incoming_enabled' => true]);
        $this->mock(ImapInbox::class)->shouldReceive('receive')->twice()->andReturnUsing(function (Mailbox $box) use ($failed): void {
            if ($box->id === $failed->id) {
                throw new RuntimeException('Private transport details');
            }
        });

        $response = $this->postJson('/api/v1/mailboxes/poll')->assertOk()->assertJsonPath('checked', 1)->assertJsonCount(1, 'errors')->assertJsonPath('errors.0.mailbox_id', $failed->id);
        $this->assertStringNotContainsString('Private transport details', $response->getContent());
        $this->assertSame('failed', $failed->fresh()->sync_status);
        $this->assertNotNull($healthy->fresh()->last_synced_at);
        $this->postJson('/api/v1/mailboxes/poll')->assertOk()->assertJsonPath('checked', 0);
    }

    public function test_polling_does_not_overlap_a_running_import_or_repeat_a_recent_scheduler_sync(): void
    {
        $this->actingAs(User::factory()->create());
        $mailbox = Mailbox::factory()->create(['incoming_enabled' => true]);
        Mailbox::factory()->create(['incoming_enabled' => true, 'last_synced_at' => now()]);
        $lock = Cache::lock('imap-import-'.$mailbox->id, 950);
        $lock->get();
        $this->mock(ImapInbox::class)->shouldNotReceive('receive');
        try {
            $this->postJson('/api/v1/mailboxes/poll')->assertOk()->assertJsonPath('checked', 0);
            $this->assertNull($mailbox->fresh()->last_synced_at);
        } finally {
            $lock->release();
        }
    }

    public function test_manual_sync_and_the_inline_command_work_without_queued_jobs(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $mailbox = Mailbox::factory()->create(['incoming_enabled' => true]);
        $this->mock(ImapInbox::class)->shouldReceive('receive')->twice();
        $this->postJson('/api/v1/mailboxes/'.$mailbox->id.'/sync')->assertOk()->assertJsonPath('message', 'Incoming mail checked. New conversations are ready.');
        $this->artisan('mailboxes:sync', ['--inline' => true, '--mailbox' => $mailbox->id])->assertSuccessful();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertNotNull($mailbox->fresh()->last_synced_at);
    }

    public function test_manual_local_sync_reports_a_connection_failure_instead_of_claiming_it_was_queued(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $mailbox = Mailbox::factory()->create(['incoming_enabled' => true]);
        $this->mock(ImapInbox::class)->shouldReceive('receive')->once()->andThrow(new RuntimeException('Private transport details'));
        $this->postJson('/api/v1/mailboxes/'.$mailbox->id.'/sync')->assertUnprocessable()->assertJsonPath('message', 'Incoming mail sync failed. Check the account connection settings.');
        $this->assertDatabaseCount('jobs', 0);
    }
}
