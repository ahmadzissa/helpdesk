<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkspaceSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RegistrationSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function account(array $changes = []): array
    {
        return array_replace(['name' => 'New Agent', 'email' => 'new@example.com', 'role' => 'agent', 'password' => 'Secure-new-account-42'], $changes);
    }

    private function settings(bool $enabled): array
    {
        return ['name' => 'Personal support', 'timezone' => 'Asia/Riyadh', 'registration_enabled' => $enabled];
    }

    public function test_additional_accounts_are_blocked_by_default_including_direct_api_requests(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/v1/workspace')->assertOk()->assertJsonPath('registration_enabled', false);
        $this->postJson('/api/v1/manage/agents', $this->account())->assertForbidden();
        $this->postJson('/api/v1/manage/agents', $this->account(['role' => 'admin']))->assertForbidden();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_admin_can_enable_account_creation_and_disable_it_again_without_losing_settings(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->putJson('/api/v1/settings', $this->settings(true))->assertOk()->assertJsonPath('registration_enabled', true);
        $this->postJson('/api/v1/manage/agents', $this->account())->assertCreated()->assertJsonMissingPath('password');
        $this->assertTrue(Hash::check('Secure-new-account-42', User::where('email', 'new@example.com')->first()->password));
        $this->putJson('/api/v1/settings', ['name' => 'Renamed', 'timezone' => 'UTC'])->assertOk()->assertJsonPath('registration_enabled', true);
        $this->putJson('/api/v1/settings', $this->settings(false))->assertOk()->assertJsonPath('registration_enabled', false);
        $this->postJson('/api/v1/manage/agents', $this->account(['email' => 'blocked@example.com']))->assertForbidden();
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseHas('activities', ['description' => 'New account registration enabled.']);
        $this->assertDatabaseHas('activities', ['description' => 'New account registration disabled.']);
    }

    public function test_guests_and_agents_cannot_enable_registration_or_add_accounts(): void
    {
        $this->putJson('/api/v1/settings', $this->settings(true))->assertUnauthorized();
        $this->postJson('/api/v1/manage/agents', $this->account())->assertUnauthorized();
        WorkspaceSetting::create(['key' => 'general', 'value' => $this->settings(true)]);
        $this->actingAs(User::factory()->create(['role' => 'agent']));
        $this->putJson('/api/v1/settings', $this->settings(false))->assertForbidden();
        $this->postJson('/api/v1/manage/agents', $this->account())->assertForbidden();
        $this->postJson('/api/v1/register', $this->account())->assertNotFound();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_disabling_registration_preserves_existing_login_and_profile_edits(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'password' => 'Current-password-42']);
        $this->actingAs($admin)->putJson('/api/v1/settings', $this->settings(false))->assertOk();
        $this->putJson('/api/v1/manage/agents/'.$admin->id, ['name' => 'Personal owner', 'email' => $admin->email, 'role' => 'admin'])->assertOk();
        $this->postJson('/api/v1/logout')->assertOk();
        $this->postJson('/api/v1/login', ['email' => $admin->email, 'password' => 'Current-password-42'])->assertOk();
        $this->assertAuthenticatedAs($admin);
    }

    public function test_first_admin_registration_closes_when_an_account_exists_and_reopens_only_when_no_accounts_remain(): void
    {
        $data = ['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'Secure-owner-password-42', 'password_confirmation' => 'Secure-owner-password-42', 'workspace_name' => 'Private inbox', 'sample_data' => false];
        $this->getJson('/api/v1/session')->assertJsonPath('needs_setup', true);
        $this->postJson('/api/v1/setup', [...$data, 'registration_enabled' => true])->assertCreated();
        $this->getJson('/api/v1/workspace')->assertJsonPath('registration_enabled', false);
        $this->getJson('/api/v1/session')->assertJsonPath('needs_setup', false);
        $this->postJson('/api/v1/setup', [...$data, 'email' => 'other@example.com'])->assertConflict();
        $this->postJson('/api/v1/logout')->assertOk();
        User::query()->delete();
        $this->getJson('/api/v1/session')->assertJsonPath('needs_setup', true);
        $this->postJson('/api/v1/setup', [...$data, 'email' => 'new-owner@example.com'])->assertCreated()->assertJsonPath('user.role', 'admin');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_saved_settings_and_disabled_registration_do_not_block_the_first_administrator(): void
    {
        WorkspaceSetting::create(['key' => 'general', 'value' => ['name' => 'Prepared workspace', 'timezone' => 'UTC', 'registration_enabled' => false]]);

        $this->get('/login')->assertRedirectToRoute('register');
        $this->get('/register')->assertOk();
        $this->getJson('/api/v1/session')->assertOk()->assertJsonPath('needs_setup', true);
        $this->postJson('/api/v1/setup', [
            'name' => 'First Admin', 'email' => 'first@example.com', 'role' => 'agent',
            'password' => 'Secure-first-account-42', 'password_confirmation' => 'Secure-first-account-42',
            'workspace_name' => 'My helpdesk', 'sample_data' => false,
        ])->assertCreated()->assertJsonPath('user.role', 'admin');

        $this->assertAuthenticatedAs(User::first());
        $this->assertSame('UTC', WorkspaceSetting::find('general')->value['timezone']);
        $this->assertFalse(WorkspaceSetting::registrationEnabled());
        $this->getJson('/api/v1/session')->assertJsonPath('needs_setup', false);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_an_existing_agent_cannot_trigger_fresh_owner_setup(): void
    {
        User::factory()->create(['role' => 'agent']);
        $this->getJson('/api/v1/session')->assertJsonPath('needs_setup', false);
        $this->postJson('/api/v1/setup', ['name' => 'Intruder', 'email' => 'intruder@example.com', 'password' => 'Secure-password-42', 'password_confirmation' => 'Secure-password-42', 'workspace_name' => 'Taken over'])->assertConflict();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_invalid_registration_values_are_rejected(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->putJson('/api/v1/settings', [...$this->settings(false), 'registration_enabled' => 'enabled'])->assertUnprocessable();
        $this->assertFalse(WorkspaceSetting::registrationEnabled());
        $this->putJson('/api/v1/settings', [...$this->settings(true), 'registration_enabled' => '1'])->assertOk()->assertJsonPath('registration_enabled', true);
    }
}
