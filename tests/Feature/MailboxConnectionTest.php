<?php

namespace Tests\Feature;

use App\Models\Mailbox;
use App\Models\User;
use App\Services\MailboxConnections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class MailboxConnectionTest extends TestCase
{
    use RefreshDatabase;

    private function settings(): array
    {
        return ['name' => 'Support', 'email' => 'support@example.com', 'color' => '#7450bb',
            'smtp_host' => 'smtp.example.com', 'smtp_port' => 587, 'smtp_encryption' => 'tls',
            'smtp_username' => 'support@example.com', 'smtp_password' => ' smtp-secret ',
            'imap_host' => 'imap.example.com', 'imap_port' => 993, 'imap_encryption' => 'ssl',
            'imap_username' => 'support@example.com', 'imap_password' => ' imap-secret ',
            'sending_enabled' => false, 'incoming_enabled' => false];
    }

    private function connections(bool $smtp = true, bool $imap = true): void
    {
        $this->partialMock(MailboxConnections::class, function ($mock) use ($smtp, $imap): void {
            $mock->shouldAllowMockingProtectedMethods();
            foreach (['smtp' => $smtp, 'imap' => $imap] as $protocol => $success) {
                $expectation = $mock->shouldReceive($protocol)->once()->with(Mockery::on(fn (Mailbox $mailbox) => $mailbox->{$protocol.'_password'} === $this->settings()[$protocol.'_password']));
                if ($success) {
                    $expectation->andReturnNull();
                } else {
                    $expectation->andThrow(new RuntimeException('Login failed for private-user with secret '.base64_encode('private-password')));
                }
            }
        });
    }

    private function token(array $settings, ?Mailbox $mailbox = null): string
    {
        $response = $this->postJson('/api/v1/mailboxes/'.($mailbox ? $mailbox->id.'/' : '').'test-connection', $settings)
            ->assertOk()->assertJsonPath('smtp.success', true)->assertJsonPath('imap.success', true);

        return $response->json('token');
    }

    public function test_both_successful_connections_are_required_to_create_an_account_even_if_sending_is_disabled(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $settings = $this->settings();
        $this->postJson('/api/v1/manage/mailboxes', $settings)->assertUnprocessable()->assertJsonValidationErrors('connection_token');
        $this->postJson('/api/v1/manage/mailboxes', [...$settings, 'connection_token' => str_repeat('x', 64), 'smtp_success' => true, 'imap_success' => true])->assertUnprocessable();
        $this->assertDatabaseCount('mailboxes', 0);
        $this->connections();
        $token = $this->token($settings);
        $this->assertDatabaseCount('mailboxes', 0);
        $this->postJson('/api/v1/manage/mailboxes', [...$settings, 'connection_token' => $token])->assertCreated()->assertJsonMissingPath('smtp_password');
        $this->assertDatabaseCount('mailboxes', 1);
        $this->assertSame(' smtp-secret ', Mailbox::first()->smtp_password);
        $this->assertNotSame(' smtp-secret ', DB::table('mailboxes')->first()->smtp_password);
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('mail_delivery_attempts', 0);
        Queue::assertNothingPushed();
    }

    public function test_smtp_failure_still_tests_imap_but_never_issues_a_save_token(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->connections(smtp: false);
        $response = $this->postJson('/api/v1/mailboxes/test-connection', $this->settings())->assertOk()
            ->assertJsonPath('smtp.success', false)->assertJsonPath('imap.success', true)->assertJsonPath('token', null);
        $this->assertStringNotContainsString('private-user', $response->getContent());
        $this->assertStringNotContainsString(base64_encode('private-password'), $response->getContent());
        $this->postJson('/api/v1/manage/mailboxes', $this->settings())->assertUnprocessable();
    }

    public function test_imap_failure_prevents_adding_the_account_after_smtp_succeeds(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->connections(imap: false);
        $this->postJson('/api/v1/mailboxes/test-connection', $this->settings())->assertOk()
            ->assertJsonPath('smtp.success', true)->assertJsonPath('imap.success', false)->assertJsonPath('token', null);
        $this->postJson('/api/v1/manage/mailboxes', $this->settings())->assertUnprocessable();
        $this->assertDatabaseCount('mailboxes', 0);
    }

    public function test_any_changed_connection_setting_requires_another_test(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $settings = $this->settings();
        $this->connections();
        $token = $this->token($settings);
        foreach (['smtp_host' => 'other.example.com', 'smtp_port' => 465, 'smtp_encryption' => 'ssl', 'smtp_username' => 'other', 'smtp_password' => 'other-secret',
            'imap_host' => 'other.example.com', 'imap_port' => 143, 'imap_encryption' => 'tls', 'imap_username' => 'other', 'imap_password' => 'other-secret'] as $field => $value) {
            $this->postJson('/api/v1/manage/mailboxes', [...$settings, $field => $value, 'connection_token' => $token])
                ->assertUnprocessable()->assertJsonValidationErrors('connection_token');
        }
        $this->assertDatabaseCount('mailboxes', 0);
    }

    public function test_test_tokens_expire_and_cannot_be_used_by_another_administrator(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $this->actingAs($owner);
        $this->connections();
        $settings = $this->settings();
        $token = $this->token($settings);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/v1/manage/mailboxes', [...$settings, 'connection_token' => $token])->assertUnprocessable();
        $this->actingAs($owner);
        $this->travel(11)->minutes();
        $this->postJson('/api/v1/manage/mailboxes', [...$settings, 'connection_token' => $token])->assertUnprocessable();
        $this->assertDatabaseCount('mailboxes', 0);
    }

    public function test_saved_passwords_can_be_tested_and_connection_changes_are_guarded(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $mailbox = Mailbox::factory()->create($this->settings());
        $settings = [...$this->settings(), 'smtp_password' => '', 'imap_password' => ''];
        $this->connections();
        $token = $this->token($settings, $mailbox);
        $this->postJson('/api/v1/manage/mailboxes', [...$this->settings(), 'email' => 'other@example.com', 'connection_token' => $token])->assertUnprocessable();
        $this->putJson('/api/v1/manage/mailboxes/'.$mailbox->id, [...$settings, 'smtp_host' => 'other.example.com'])->assertUnprocessable();
        $this->putJson('/api/v1/manage/mailboxes/'.$mailbox->id, [...$settings, 'sending_enabled' => true])->assertUnprocessable();
        $this->putJson('/api/v1/manage/mailboxes/'.$mailbox->id, [...$settings, 'sending_enabled' => true, 'connection_token' => $token])->assertOk();
        $this->assertSame(' smtp-secret ', $mailbox->fresh()->smtp_password);
        $this->assertSame('smtp.example.com', $mailbox->fresh()->smtp_host);
    }

    public function test_editing_only_account_metadata_does_not_require_a_new_test(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $mailbox = Mailbox::factory()->create($this->settings());
        $this->putJson('/api/v1/manage/mailboxes/'.$mailbox->id, [...$this->settings(), 'name' => 'Renamed support', 'smtp_password' => '', 'imap_password' => ''])->assertOk();
        $this->assertSame('Renamed support', $mailbox->fresh()->name);
    }

    public function test_account_import_start_is_the_save_time_and_cannot_be_overridden_by_the_client(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->connections();
        $settings = $this->settings();
        $token = $this->token($settings);
        $this->travel(2)->minutes();
        $this->freezeSecond();
        $this->postJson('/api/v1/manage/mailboxes', [...$settings, 'connection_token' => $token,
            'import_started_at' => '2000-01-01 00:00:00', 'imap_uidvalidity' => 99, 'imap_last_uid' => 123])->assertCreated();
        $mailbox = Mailbox::firstOrFail();
        $this->assertTrue($mailbox->import_started_at->equalTo(now()));
        $this->assertNull($mailbox->imap_uidvalidity);
        $this->assertSame(0, $mailbox->imap_last_uid);
    }

    public function test_metadata_edits_preserve_the_cursor_and_switching_the_imap_account_starts_from_now(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $settings = $this->settings();
        $mailbox = Mailbox::factory()->create($settings);
        $mailbox->forceFill(['imap_uidvalidity' => 500, 'imap_last_uid' => 123, 'last_synced_at' => now()])->save();
        $start = $mailbox->fresh()->import_started_at;
        $this->travel(1)->hour();
        $this->putJson('/api/v1/manage/mailboxes/'.$mailbox->id, [...$settings, 'name' => 'Renamed',
            'import_started_at' => '2000-01-01 00:00:00', 'imap_last_uid' => 0])->assertOk();
        $this->assertTrue($mailbox->fresh()->import_started_at->equalTo($start));
        $this->assertSame(123, $mailbox->fresh()->imap_last_uid);

        $this->connections();
        $settings['imap_username'] = 'other@example.com';
        $token = $this->token($settings, $mailbox);
        $this->freezeSecond();
        $this->putJson('/api/v1/manage/mailboxes/'.$mailbox->id, [...$settings, 'connection_token' => $token])->assertOk();
        $mailbox->refresh();
        $this->assertTrue($mailbox->import_started_at->equalTo(now()));
        $this->assertNull($mailbox->imap_uidvalidity);
        $this->assertSame(0, $mailbox->imap_last_uid);
        $this->assertNull($mailbox->last_synced_at);
    }

    public function test_guests_and_agents_cannot_test_saved_or_unsaved_credentials(): void
    {
        $mailbox = Mailbox::factory()->create($this->settings());
        $this->mock(MailboxConnections::class)->shouldNotReceive('test');
        $this->postJson('/api/v1/mailboxes/test-connection', $this->settings())->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'agent']));
        $this->postJson('/api/v1/mailboxes/test-connection', $this->settings())->assertForbidden();
        $this->postJson('/api/v1/mailboxes/'.$mailbox->id.'/test-connection', $this->settings())->assertForbidden();
    }

    public function test_invalid_or_incomplete_settings_do_not_attempt_connections(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->mock(MailboxConnections::class)->makePartial()->shouldNotReceive('test');
        $this->postJson('/api/v1/mailboxes/test-connection', [...$this->settings(), 'smtp_host' => 'https://smtp.example.com', 'imap_port' => 0])->assertUnprocessable();
        $this->postJson('/api/v1/mailboxes/test-connection', [...$this->settings(), 'imap_host' => ''])->assertUnprocessable();
        $this->postJson('/api/v1/mailboxes/test-connection', [...$this->settings(), 'imap_password' => '', 'smtp_password' => ''])->assertUnprocessable()->assertJsonValidationErrors(['imap_password', 'smtp_password']);
    }

    public function test_testing_does_not_resume_receive_only_mode_or_record_a_bounce(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        DB::table('mail_safety')->where('id', 1)->update(['paused_at' => now(), 'reason' => 'Administrator review']);
        $this->connections();
        $this->token($this->settings());
        $this->assertNotNull(DB::table('mail_safety')->where('id', 1)->value('paused_at'));
        $this->assertDatabaseCount('mail_delivery_attempts', 0);
    }

    public function test_a_failed_retest_invalidates_previous_success(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->connections();
        $settings = $this->settings();
        $token = $this->token($settings);
        $this->connections(imap: false);
        $this->postJson('/api/v1/mailboxes/test-connection', $settings)->assertOk()->assertJsonPath('token', null);
        $this->postJson('/api/v1/manage/mailboxes', [...$settings, 'connection_token' => $token])->assertUnprocessable();
    }

    public function test_verification_cache_does_not_store_passwords(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user);
        $this->connections();
        $this->token($this->settings());
        $proof = Cache::get('mailbox-connection:'.$user->id.':new');
        $this->assertCount(2, $proof);
        $this->assertStringNotContainsString('secret', json_encode($proof));
    }
}
