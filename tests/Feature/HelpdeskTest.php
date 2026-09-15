<?php

namespace Tests\Feature;

use App\Jobs\SendTicketReply;
use App\Models\Automation;
use App\Models\CannedReply;
use App\Models\Mailbox;
use App\Models\SavedView;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\TicketDraft;
use App\Models\User;
use App\Models\WorkspaceSetting;
use App\Services\MailboxConnections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HelpdeskTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user);

        return $user;
    }

    public function test_guest_cannot_read_or_change_workspace_data(): void
    {
        $ticket = Ticket::factory()->create();
        $this->getJson('/api/v1/tickets')->assertUnauthorized();
        $this->getJson('/api/v1/workspace')->assertUnauthorized();
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', ['body' => 'secret'])->assertUnauthorized();
        $this->getJson('/api/v1/session')->assertOk()->assertJsonPath('needs_setup', true);
    }

    public function test_setup_creates_one_administrator_and_rejects_second_setup(): void
    {
        $data = ['name' => 'Workspace Admin', 'email' => 'admin@example.com', 'password' => 'A-secure-password-42', 'password_confirmation' => 'A-secure-password-42', 'workspace_name' => 'Support', 'sample_data' => false];
        $this->postJson('/api/v1/setup', $data)->assertCreated()->assertJsonPath('user.role', 'admin')->assertJsonMissingPath('user.password');
        $this->assertAuthenticated();
        $this->assertTrue(Hash::check($data['password'], User::first()->password));
        $this->postJson('/api/v1/setup', [...$data, 'email' => 'other@example.com'])->assertConflict();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_setup_can_load_the_sample_workspace_without_enabling_email(): void
    {
        $this->postJson('/api/v1/setup', ['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'A-secure-password-42', 'password_confirmation' => 'A-secure-password-42', 'workspace_name' => 'Demo', 'sample_data' => true])->assertCreated();
        $this->assertDatabaseCount('tickets', 12);
        $this->assertDatabaseHas('tickets', ['id' => 1042]);
        $this->assertSame(0, Mailbox::where('sending_enabled', true)->count());
        $this->assertSame(0, Automation::where('enabled', true)->count());
    }

    public function test_login_checks_password_and_logout_ends_session(): void
    {
        $user = User::factory()->create(['password' => 'correct-password-42']);
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'correct-password-42'])->assertOk();
        $this->assertAuthenticatedAs($user);
        $this->postJson('/api/v1/logout')->assertOk();
        $this->assertGuest();
    }

    public function test_open_is_exact_and_saved_views_combine_filters_with_mailbox_scope(): void
    {
        $user = $this->admin();
        $box = Mailbox::factory()->create();
        $other = Mailbox::factory()->create();
        Ticket::factory()->create(['status' => 'Open', 'mailbox_id' => $box->id, 'priority' => 'High', 'tags' => ['Billing'], 'assignee_id' => $user->id]);
        Ticket::factory()->create(['status' => 'Pending', 'mailbox_id' => $box->id, 'priority' => 'High', 'tags' => ['Billing']]);
        Ticket::factory()->create(['status' => 'Open', 'mailbox_id' => $box->id, 'priority' => 'Low', 'tags' => ['Billing']]);
        Ticket::factory()->create(['status' => 'Open', 'mailbox_id' => $other->id, 'priority' => 'High', 'tags' => ['Billing']]);
        Ticket::factory()->create(['status' => 'Open', 'folder' => 'archive', 'mailbox_id' => $box->id, 'priority' => 'High', 'tags' => ['Billing']]);
        $view = SavedView::factory()->create(['tag' => 'Billing', 'filters' => ['status' => 'Open', 'priority' => 'High']]);
        $this->getJson('/api/v1/tickets?view=Open&mailbox_id='.$box->id)->assertOk()->assertJsonPath('total', 2)->assertJsonPath('counts.Open', 2);
        $this->getJson('/api/v1/tickets?view=saved:'.$view->id.'&mailbox_id='.$box->id)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('views.0.count', 1);
        $this->getJson('/api/v1/tickets?view=archive&mailbox_id='.$box->id)->assertJsonPath('total', 1);
    }

    public function test_unassigned_legacy_tag_view_and_search_message_body(): void
    {
        $this->admin();
        $ticket = Ticket::factory()->create(['tags' => ['Orders']]);
        $ticket->messages()->create(['body' => 'Tracking token UNIQUE-432', 'kind' => 'inbound']);
        $view = SavedView::factory()->create(['tag' => 'Orders', 'filters' => []]);
        $this->getJson('/api/v1/tickets?view=saved:'.$view->id)->assertJsonPath('total', 1);
        $this->getJson('/api/v1/tickets?view=unassigned&search=UNIQUE-432')->assertJsonPath('tickets.0.id', $ticket->id);
        $this->postJson('/api/v1/manage/views', ['name' => 'Invalid', 'filters' => ['rating' => '5']])->assertUnprocessable();
    }

    public function test_account_group_scope_follows_mailboxes_not_responsibility(): void
    {
        $this->admin();
        $group = Team::factory()->create();
        $other = Team::factory()->create();
        $box = Mailbox::factory()->create(['team_id' => $group->id]);
        Ticket::factory()->create(['mailbox_id' => $box->id, 'team_id' => $other->id]);
        $this->getJson('/api/v1/tickets?team_id='.$group->id)->assertJsonPath('total', 1);
        $this->getJson('/api/v1/tickets?team_id='.$other->id)->assertJsonPath('total', 0);
    }

    public function test_ticket_create_validate_update_and_restore(): void
    {
        $this->admin();
        $this->postJson('/api/v1/tickets', ['subject' => 'Missing requester'])->assertUnprocessable();
        $response = $this->postJson('/api/v1/tickets', ['subject' => 'Please help', 'requester_email' => 'customer@example.com', 'body' => 'My request']);
        $response->assertCreated()->assertJsonPath('data.messages.0.body', 'My request')->assertJsonPath('data.messages.0.kind', 'outbound')->assertJsonPath('data.messages.0.delivery', 'saved')->assertJsonPath('data.status', 'Pending');
        $id = $response->json('data.id');
        $this->patchJson('/api/v1/tickets/'.$id, ['status' => 'Solved', 'folder' => 'trash'])->assertOk();
        $this->assertNotNull(Ticket::find($id)->resolved_at);
        $this->patchJson('/api/v1/tickets/'.$id, ['status' => 'Open', 'folder' => 'inbox'])->assertOk();
        $this->assertNull(Ticket::find($id)->resolved_at);
        $this->assertDatabaseHas('tickets', ['id' => $id, 'folder' => 'inbox', 'status' => 'Open']);
    }

    public function test_new_agent_ticket_queues_its_first_message_and_records_the_agent_as_sender(): void
    {
        Queue::fake();
        $agent = $this->admin();
        $mailbox = Mailbox::factory()->create(['sending_enabled' => true, 'smtp_host' => 'smtp.example.com']);
        $response = $this->postJson('/api/v1/tickets', ['subject' => 'A new conversation', 'requester_email' => 'customer@example.com', 'body' => 'Your first message.', 'mailbox_id' => $mailbox->id]);
        $response->assertCreated()->assertJsonPath('data.status', 'Pending')->assertJsonPath('data.assignee_id', $agent->id)
            ->assertJsonPath('data.unread', false)->assertJsonPath('data.messages.0.kind', 'outbound')
            ->assertJsonPath('data.messages.0.author_name', $agent->name)->assertJsonPath('data.messages.0.author_email', $mailbox->email)
            ->assertJsonPath('data.messages.0.delivery', 'queued');
        $id = $response->json('data.messages.0.id');
        Queue::assertPushed(SendTicketReply::class, fn ($job) => $job->message->id === $id);
        $this->assertDatabaseMissing('messages', ['ticket_id' => $response->json('data.id'), 'kind' => 'inbound']);
    }

    public function test_new_ticket_preserves_translation_and_sending_pause_requirements(): void
    {
        Queue::fake();
        $this->admin();
        $mailbox = Mailbox::factory()->create(['sending_enabled' => true, 'smtp_host' => 'smtp.example.com']);
        WorkspaceSetting::updateOrCreate(['key' => 'translation'], ['value' => ['outgoing' => true]]);
        $data = ['subject' => 'First contact', 'requester_email' => 'customer@example.com', 'body' => 'Your first message.', 'mailbox_id' => $mailbox->id, 'customer_language' => 'ar'];
        $this->postJson('/api/v1/tickets', $data)->assertCreated()->assertJsonPath('data.messages.0.delivery', 'translation_pending')
            ->assertJsonPath('data.customer_language.language', 'ar')->assertJsonPath('data.customer_language.manual', 1);
        $this->assertDatabaseHas('customer_languages', ['email' => 'customer@example.com', 'language' => 'ar', 'source_message_id' => null]);
        Queue::assertNotPushed(SendTicketReply::class);

        WorkspaceSetting::find('translation')->update(['value' => ['outgoing' => false]]);
        DB::table('mail_safety')->where('id', 1)->update(['paused_at' => now()]);
        $this->postJson('/api/v1/tickets', $data)->assertCreated()->assertJsonPath('data.messages.0.delivery', 'held');
        Queue::assertNotPushed(SendTicketReply::class);
    }

    public function test_bulk_is_validated_before_any_ticket_is_changed(): void
    {
        $user = $this->admin();
        $tickets = Ticket::factory()->count(2)->create();
        $this->postJson('/api/v1/tickets/bulk', ['ids' => [$tickets[0]->id, 999999], 'changes' => ['status' => 'Closed']])->assertUnprocessable();
        $this->assertSame(2, Ticket::where('status', 'Open')->count());
        $this->postJson('/api/v1/tickets/bulk', ['ids' => $tickets->modelKeys(), 'changes' => ['status' => 'Pending', 'assignee_id' => $user->id], 'tag' => 'Review'])->assertOk()->assertJsonPath('updated', 2);
        foreach ($tickets as $ticket) {
            $this->assertSame(['Review'], $ticket->fresh()->tags);
            $this->assertSame('Pending', $ticket->fresh()->status);
        }
    }

    public function test_private_notes_and_unconnected_replies_are_saved_without_email(): void
    {
        $user = $this->admin();
        Queue::fake();
        $ticket = Ticket::factory()->create(['mailbox_id' => Mailbox::factory()->create()->id]);
        TicketDraft::create(['ticket_id' => $ticket->id, 'user_id' => $user->id, 'body' => 'Draft', 'private' => false]);
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', ['body' => 'Team only', 'private' => true])->assertOk()->assertJsonPath('data.messages.0.kind', 'note')->assertJsonPath('data.messages.0.delivery', null);
        $this->assertDatabaseCount('ticket_drafts', 0);
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', ['body' => 'Saved reply', 'private' => false])->assertOk()->assertJsonPath('data.messages.1.delivery', 'saved');
        Queue::assertNothingPushed();
    }

    public function test_connected_reply_is_queued_and_private_note_is_never_queued(): void
    {
        $this->admin();
        Queue::fake();
        $box = Mailbox::factory()->create(['sending_enabled' => true, 'smtp_host' => 'smtp.example.com']);
        $ticket = Ticket::factory()->create(['mailbox_id' => $box->id]);
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', ['body' => 'Reply', 'private' => false])->assertOk()->assertJsonPath('data.messages.0.delivery', 'queued');
        Queue::assertPushed(SendTicketReply::class, 1);
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', ['body' => 'Internal', 'private' => true])->assertOk();
        Queue::assertPushed(SendTicketReply::class, 1);
    }

    public function test_drafts_are_isolated_per_agent(): void
    {
        $one = $this->admin();
        $ticket = Ticket::factory()->create();
        $this->putJson('/api/v1/tickets/'.$ticket->id.'/draft', ['body' => 'My private draft', 'private' => true])->assertOk();
        $this->actingAs(User::factory()->create());
        $this->getJson('/api/v1/tickets/'.$ticket->id)->assertJsonPath('draft', null);
        $this->actingAs($one);
        $this->getJson('/api/v1/tickets/'.$ticket->id)->assertJsonPath('draft.body', 'My private draft');
    }

    public function test_attachments_are_private_and_paths_are_not_exposed(): void
    {
        $this->admin();
        Storage::fake('local');
        $ticket = Ticket::factory()->create();
        $response = $this->post('/api/v1/tickets/'.$ticket->id.'/messages', ['body' => 'Attached', 'private' => true, 'attachments' => [UploadedFile::fake()->create('details.txt', 1, 'text/plain')]], ['Accept' => 'application/json']);
        $response->assertOk()->assertJsonMissingPath('data.messages.0.attachments.0.path');
        $message = $ticket->messages()->first();
        Storage::disk('local')->assertExists($message->attachments[0]['path']);
        $this->get('/api/v1/attachments/'.$message->id.'/0')->assertOk()->assertDownload('details.txt');
        $this->postJson('/api/v1/logout');
        $this->getJson('/api/v1/attachments/'.$message->id.'/0')->assertUnauthorized();
    }

    public function test_admin_settings_encrypt_passwords_and_agent_cannot_change_them(): void
    {
        $this->admin();
        $this->partialMock(MailboxConnections::class, function ($mock): void {
            $mock->shouldAllowMockingProtectedMethods();
            $mock->shouldReceive('smtp')->once();
            $mock->shouldReceive('imap')->once();
        });
        $payload = ['name' => 'Inbox', 'email' => 'inbox@example.com', 'color' => '#7450bb', 'smtp_host' => 'smtp.example.com', 'smtp_port' => 587, 'smtp_encryption' => 'tls', 'smtp_password' => 'smtp-secret', 'imap_host' => 'imap.example.com', 'imap_username' => 'inbox@example.com', 'imap_password' => 'imap-secret', 'imap_port' => 993, 'imap_encryption' => 'ssl', 'sending_enabled' => false];
        $payload['connection_token'] = $this->postJson('/api/v1/mailboxes/test-connection', $payload)->assertOk()->json('token');
        $response = $this->postJson('/api/v1/manage/mailboxes', $payload)->assertCreated()->assertJsonMissingPath('smtp_password');
        $id = $response->json('id');
        $this->assertSame('smtp-secret', Mailbox::find($id)->smtp_password);
        $this->assertNotSame('smtp-secret', DB::table('mailboxes')->find($id)->smtp_password);
        $this->putJson('/api/v1/manage/mailboxes/'.$id, [...$payload, 'smtp_password' => ''])->assertOk();
        $this->assertSame('smtp-secret', Mailbox::find($id)->smtp_password);
        $this->actingAs(User::factory()->create(['role' => 'agent']));
        $this->getJson('/api/v1/workspace')->assertJsonMissingPath('mailboxes.0.smtp_host')->assertJsonMissingPath('mailboxes.0.smtp_password');
        $this->postJson('/api/v1/manage/mailboxes', $payload)->assertForbidden();
        $this->putJson('/api/v1/settings', ['name' => 'Changed', 'timezone' => 'UTC'])->assertForbidden();
    }

    public function test_automations_match_all_conditions_and_run_only_once(): void
    {
        $this->admin();
        Queue::fake();
        $reply = CannedReply::factory()->create();
        $rule = Automation::factory()->create(['trigger' => 'ticket.updated', 'conditions' => ['subject_contains' => 'refund', 'status' => 'Open'], 'actions' => ['priority' => 'High', 'tag' => 'Refund', 'reply_id' => $reply->id]]);
        $ticket = Ticket::factory()->create(['subject' => 'Refund please', 'requester_name' => 'Olivia']);
        $this->patchJson('/api/v1/tickets/'.$ticket->id, ['company' => 'Acme'])->assertOk();
        $this->patchJson('/api/v1/tickets/'.$ticket->id, ['company' => 'Acme Ltd'])->assertOk();
        $this->assertDatabaseCount('automation_runs', 1);
        $this->assertSame('High', $ticket->fresh()->priority);
        $this->assertSame(['Refund'], $ticket->fresh()->tags);
        $this->assertSame(1, $ticket->messages()->count());
        $this->assertStringContainsString('Hello Olivia', $ticket->messages()->first()->body);
        $this->assertSame($rule->name, $ticket->messages()->first()->rule_name);
        $pending = Ticket::factory()->create(['subject' => 'Refund please', 'status' => 'Pending']);
        $this->patchJson('/api/v1/tickets/'.$pending->id, ['company' => 'Acme'])->assertOk();
        $this->assertSame(0, $pending->messages()->count());
        Queue::assertNothingPushed();
    }

    public function test_profile_preferences_validate_and_password_change_requires_current_password(): void
    {
        $user = $this->admin();
        $this->patchJson('/api/v1/profile', ['preferences' => ['theme' => 'forest', 'initial_view' => 'Open', 'source_indicators' => false]])->assertOk()->assertJsonPath('preferences.theme', 'forest');
        $this->patchJson('/api/v1/profile', ['preferences' => ['colors' => ['accent' => 'red;bad']]])->assertUnprocessable();
        $this->patchJson('/api/v1/profile', ['password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])->assertUnprocessable();
        $this->assertFalse(Hash::check('new-password-123', $user->fresh()->password));
    }

    public function test_reports_count_saved_tickets_and_handle_empty_period(): void
    {
        $this->admin();
        $this->getJson('/api/v1/reports?days=7')->assertOk()->assertJsonPath('total', 0)->assertJsonPath('response_minutes', null);
        Ticket::factory()->create(['status' => 'Solved', 'resolved_at' => now()]);
        Ticket::factory()->create(['status' => 'Pending']);
        $this->getJson('/api/v1/reports?days=7')->assertOk()->assertJsonPath('total', 2)->assertJsonPath('resolved', 1)->assertJsonPath('resolution_rate', 50)->assertJsonCount(7, 'trend');
    }
}
