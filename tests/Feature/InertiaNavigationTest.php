<?php

namespace Tests\Feature;

use App\Models\Mailbox;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkspaceSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InertiaNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_workspace_opens_first_admin_registration_through_laravel(): void
    {
        $this->get('/')->assertRedirectToRoute('register');
        $this->get('/login')->assertRedirectToRoute('register');
        $this->get('/register')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Auth')->where('mode', 'register')->where('needs_setup', true)
            ->where('auth.user', null)->where('workspace', null)->where('base_path', '')
            ->has('csrf_token'));
    }

    public function test_guest_deep_links_require_login_and_registration_closes_after_setup(): void
    {
        User::factory()->create();
        $this->get('/settings?section=accounts')->assertRedirectToRoute('login');
        $this->get('/register')->assertRedirectToRoute('login');
        $this->get('/login')->assertInertia(fn (Assert $page) => $page->component('Auth')->where('mode', 'login')->where('needs_setup', false));
        $this->getJson('/api/v1/tickets')->assertUnauthorized();
    }

    public function test_inertia_signup_validates_then_creates_only_the_first_administrator(): void
    {
        $this->from('/register')->post('/register', ['email' => 'invalid'])->assertRedirect('/register')->assertSessionHasErrors(['name', 'email', 'password']);
        $data = ['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'Secure-owner-password-42', 'password_confirmation' => 'Secure-owner-password-42', 'workspace_name' => 'Private support', 'sample_data' => false];
        $this->post('/register', [...$data, 'role' => 'agent'])->assertRedirectToRoute('tickets.index');
        $this->assertAuthenticatedAs(User::first());
        $this->assertSame('admin', User::first()->role);
        $this->assertFalse(WorkspaceSetting::registrationEnabled());
        $this->post('/register', [...$data, 'email' => 'another@example.com'])->assertConflict();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_login_uses_validation_errors_and_returns_to_the_requested_page(): void
    {
        $user = User::factory()->create(['password' => 'Secure-login-password-42']);
        $this->get('/settings?section=accounts')->assertRedirectToRoute('login');
        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertRedirect('/login');
        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('errors.email', 'The email or password is incorrect.')->etc());
        $this->post('/login', ['email' => $user->email, 'password' => 'Secure-login-password-42'])->assertRedirect('/settings?section=accounts');
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_and_home_respect_the_initial_inbox_preference(): void
    {
        $user = User::factory()->create(['password' => 'Secure-login-password-42', 'preferences' => ['initial_view' => 'mine']]);
        $this->post('/login', ['email' => $user->email, 'password' => 'Secure-login-password-42'])->assertRedirectToRoute('tickets.index', ['view' => 'mine']);
        $this->get('/')->assertRedirectToRoute('tickets.index', ['view' => 'mine']);
    }

    public function test_all_workspace_pages_return_inertia_responses_with_private_shared_data(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Mailbox::factory()->create(['smtp_password' => 'smtp-secret', 'imap_password' => 'imap-secret']);
        WorkspaceSetting::create(['key' => 'translation_key', 'value' => ['key' => 'translation-secret']]);
        $ticket = Ticket::factory()->create();
        $this->actingAs($admin);
        foreach (['/tickets' => 'Inbox', '/tickets/'.$ticket->id => 'Ticket', '/automations' => 'Workflows', '/settings?section=accounts' => 'Workspace', '/replies' => 'Workspace', '/reports' => 'Workspace', '/profile' => 'Workspace', '/activity' => 'Workspace'] as $url => $component) {
            $response = $this->get($url);
            $response->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component($component)->where('auth.user.id', $admin->id)->has('workspace.agents', 1)
                ->missing('auth.user.password')->missing('auth.user.remember_token')
                ->missing('workspace.mailboxes.0.smtp_password')->missing('workspace.mailboxes.0.imap_password')
                ->missing('workspace.settings.translation_key'));
            $response->assertViewHas('page', fn (array $page) => $page['encryptHistory'] === true);
            $this->assertStringNotContainsString('smtp-secret', $response->getContent());
            $this->assertStringNotContainsString('translation-secret', $response->getContent());
        }
        $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json'))])
            ->get('/tickets')->assertOk()->assertHeader('X-Inertia', 'true')->assertJsonPath('component', 'Inbox')->assertJsonPath('props.auth.user.id', $admin->id);
    }

    public function test_unknown_pages_and_missing_tickets_return_not_found(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get('/tickets/999999')->assertNotFound();
        $this->get('/unknown-page')->assertNotFound();
        $this->getJson('/api/v1/unknown')->assertNotFound();
    }

    public function test_subfolder_navigation_and_redirects_keep_the_application_base_path(): void
    {
        $this->withServerVariables(['SCRIPT_NAME' => '/helpdesk/public/index.php', 'PHP_SELF' => '/helpdesk/public/index.php', 'SCRIPT_FILENAME' => public_path('index.php')]);
        $this->get('http://localhost/helpdesk/public/login')->assertRedirect('http://localhost/helpdesk/public/register');
        $this->get('http://localhost/helpdesk/public/register')->assertInertia(fn (Assert $page) => $page
            ->component('Auth')->where('base_path', '/helpdesk/public')->where('navigation.path', '/register')
            ->url('/helpdesk/public/register'));
        $this->actingAs(User::factory()->create());
        $this->get('http://localhost/helpdesk/public/settings?section=translation')->assertInertia(fn (Assert $page) => $page
            ->component('Workspace')->where('base_path', '/helpdesk/public')->where('navigation.path', '/settings')
            ->where('navigation.params.page', 'settings')->where('navigation.query.section', 'translation')
            ->url('/helpdesk/public/settings?section=translation'));
    }

    public function test_logout_clears_inertia_history_and_guest_cannot_revisit_tickets(): void
    {
        $this->actingAs(User::factory()->create())->post('/logout')->assertRedirectToRoute('login');
        $this->assertGuest();
        $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json'))])
            ->get('/login')->assertOk()->assertJsonPath('clearHistory', true)->assertJsonPath('props.auth.user', null);
        $this->get('/tickets')->assertRedirectToRoute('login');
    }

    public function test_password_recovery_uses_inertia_errors_and_redirects(): void
    {
        $user = User::factory()->create();
        $this->get('/forgot-password')->assertInertia(fn (Assert $page) => $page->component('Auth')->where('mode', 'forgot'));
        $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email])->assertRedirect('/forgot-password')->assertSessionHas('notice');
        $token = Password::createToken($user);
        $this->get('/reset-password?email='.urlencode($user->email).'&token='.$token)->assertInertia(fn (Assert $page) => $page->component('Auth')->where('mode', 'reset')->where('navigation.query.token', $token));
        $data = ['email' => $user->email, 'token' => 'invalid', 'password' => 'New-secure-password-42', 'password_confirmation' => 'New-secure-password-42'];
        $this->from('/reset-password')->post('/reset-password', $data)->assertRedirect('/reset-password')->assertSessionHasErrors('email');
        $this->post('/reset-password', [...$data, 'token' => $token])->assertRedirectToRoute('login')->assertSessionHas('notice', 'Password updated. Sign in with your new password.');
        $this->assertTrue(Hash::check('New-secure-password-42', $user->fresh()->password));
        $this->assertGuest();
    }
}
