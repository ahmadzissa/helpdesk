<?php

namespace Tests\Feature;

use App\Models\CannedReply;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Services\CannedImages;
use App\Services\OutgoingMail;
use App\Services\WorkflowActions;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class CannedReplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_removing_an_image_updates_the_template_and_preserves_existing_ticket_messages(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => 'agent']));
        $image = $this->post('/api/v1/canned-images', ['image' => UploadedFile::fake()->image('used.png')], ['Accept' => 'application/json'])->assertCreated()->json();
        $record = DB::table('canned_reply_images')->sole();
        $body = 'Before '.$image['markdown'].' After';
        $reply = CannedReply::factory()->create(['body' => $body]);
        $ticket = Ticket::factory()->create();
        $message = Message::factory()->create(['ticket_id' => $ticket->id, 'kind' => 'outbound', 'delivery' => 'sent', 'body' => $body]);
        $this->putJson('/api/v1/tickets/'.$ticket->id.'/draft', ['body' => $body, 'private' => false])->assertSuccessful();

        $this->deleteJson($image['url'])->assertOk()->assertJson(['deleted' => false]);
        $this->putJson('/api/v1/manage/replies/'.$reply->id, ['title' => $reply->title, 'shortcut' => $reply->shortcut, 'category' => $reply->category, 'body' => 'Updated response'])->assertOk();

        $this->assertSame('Updated response', $reply->fresh()->body);
        $this->assertSame($body, $message->fresh()->body);
        $this->getJson('/api/v1/tickets/'.$ticket->id)->assertOk()->assertJsonPath('draft.body', $body);
        Storage::disk('local')->assertExists($record->path);
        $this->get($image['url'])->assertOk();
        $email = new Email;
        $this->assertStringContainsString('@relay.canned', app(OutgoingMail::class)->html($message, $email));
    }

    public function test_saving_or_deleting_a_response_removes_unused_image_files_and_database_rows(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        foreach (['edit', 'delete'] as $action) {
            $image = $this->post('/api/v1/canned-images', ['image' => UploadedFile::fake()->image($action.'.png')], ['Accept' => 'application/json'])->assertCreated()->json();
            $record = DB::table('canned_reply_images')->sole();
            $reply = CannedReply::factory()->create(['body' => $image['markdown']]);
            $this->deleteJson($image['url'])->assertOk()->assertJson(['deleted' => false]);
            if ($action === 'edit') {
                $this->putJson('/api/v1/manage/replies/'.$reply->id, ['title' => $reply->title, 'shortcut' => $reply->shortcut, 'category' => $reply->category, 'body' => 'No image needed'])->assertOk();
            } else {
                $this->deleteJson('/api/v1/manage/replies/'.$reply->id)->assertOk();
            }
            Storage::disk('local')->assertMissing($record->path);
            $this->assertDatabaseMissing('canned_reply_images', ['id' => $record->id]);
            $this->get($image['url'])->assertNotFound();
        }
    }

    public function test_canned_image_deletion_requires_login_and_does_not_delete_other_images(): void
    {
        Storage::fake('local');
        $this->deleteJson('/api/v1/canned-images/00000000-0000-0000-0000-000000000000')->assertUnauthorized();
        $this->actingAs(User::factory()->create());
        $this->deleteJson('/api/v1/canned-images/00000000-0000-0000-0000-000000000000')->assertOk()->assertJson(['deleted' => true]);
        $this->deleteJson('/api/v1/canned-images/invalid')->assertNotFound();
        $first = $this->post('/api/v1/canned-images', ['image' => UploadedFile::fake()->image('first.png')], ['Accept' => 'application/json'])->assertCreated()->json();
        $second = $this->post('/api/v1/canned-images', ['image' => UploadedFile::fake()->image('second.png')], ['Accept' => 'application/json'])->assertCreated()->json();
        $record = DB::table('canned_reply_images')->where('name', 'first.png')->first();
        $this->deleteJson($first['url'])->assertOk()->assertJson(['deleted' => true]);
        Storage::disk('local')->assertMissing($record->path);
        $this->assertDatabaseMissing('canned_reply_images', ['id' => $record->id]);
        $this->get($second['url'])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertCount(1, app(CannedImages::class)->images($second['markdown']));
    }

    public function test_failed_file_deletion_keeps_the_image_database_row(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $image = $this->post('/api/v1/canned-images', ['image' => UploadedFile::fake()->image('retry.png')], ['Accept' => 'application/json'])->assertCreated()->json();
        $record = DB::table('canned_reply_images')->sole();
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->once()->with($record->path)->andReturnTrue();
        $disk->shouldReceive('delete')->once()->with($record->path)->andReturnFalse();
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);

        $this->deleteJson($image['url'])->assertStatus(500);

        $this->assertDatabaseHas('canned_reply_images', ['id' => $record->id, 'path' => $record->path]);
    }

    public function test_agents_can_save_edit_and_find_comma_separated_shortcuts_with_spaces(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'agent']));
        $payload = ['title' => 'Export', 'category' => 'General', 'shortcut' => ' export reviews , #CSV   file ', 'body' => '**Hello** {{name}}'];
        $this->postJson('/api/v1/manage/replies', $payload)->assertSuccessful();
        $reply = CannedReply::sole();
        $this->assertSame('#export reviews, #CSV file', $reply->shortcut);
        $this->putJson('/api/v1/manage/replies/'.$reply->id, $payload)->assertSuccessful();
        $this->getJson('/api/v1/workspace')->assertJsonFragment(['shortcut' => '#export reviews, #CSV file', 'body' => '**Hello** {{name}}']);
        $payload['shortcut'] = '#other, #csv FILE';
        $this->postJson('/api/v1/manage/replies', $payload)->assertUnprocessable()->assertJsonValidationErrors('shortcut');
        $this->assertDatabaseCount('canned_replies', 1);
    }

    public function test_empty_duplicate_and_invalid_shortcuts_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (['#', '#hello,', '#same, SAME', '#bad/shortcut', '#'.str_repeat('a', 40)] as $shortcut) {
            $this->postJson('/api/v1/manage/replies', ['title' => 'Example', 'category' => 'General', 'shortcut' => $shortcut, 'body' => 'Hello'])
                ->assertUnprocessable()->assertJsonValidationErrors('shortcut');
        }
        $this->assertDatabaseCount('canned_replies', 0);
    }

    public function test_canned_images_are_reusable_by_other_agents_and_embedded_after_the_response_is_deleted(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $image = $this->post('/api/v1/canned-images', ['image' => UploadedFile::fake()->image('guide.png')], ['Accept' => 'application/json'])->assertCreated()->json();
        $this->get($image['url'])->assertOk()->assertHeader('Content-Type', 'image/png');
        $body = '**Instructions**\n\n'.$image['markdown'];
        $this->postJson('/api/v1/manage/replies', ['title' => 'Guide', 'category' => 'General', 'shortcut' => 'guide, help me', 'body' => $body])->assertSuccessful();
        $reply = CannedReply::sole();
        $this->travel(90)->days();
        $this->actingAs(User::factory()->create());
        $this->get($image['url'])->assertOk();
        $box = Mailbox::factory()->create();
        $tickets = Ticket::factory()->count(2)->create(['mailbox_id' => $box->id]);
        foreach ($tickets as $ticket) {
            $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', ['body' => $reply->body])->assertOk();
        }
        $this->deleteJson('/api/v1/manage/replies/'.$reply->id)->assertSuccessful();
        foreach ($tickets as $ticket) {
            $email = new Email;
            $html = app(OutgoingMail::class)->html($ticket->messages()->sole(), $email);
            $this->assertStringContainsString('<strong>Instructions</strong>', $html);
            $this->assertStringContainsString('@relay.canned', $html);
            $this->assertStringNotContainsString('/api/v1/canned-images/', $html);
            $parts = array_filter($email->getAttachments(), fn ($part) => $part->getFilename() === 'guide.png');
            $this->assertCount(1, $parts);
            $this->assertSame('inline', array_values($parts)[0]->getDisposition());
        }
        $tickets->first()->delete();
        $this->get($image['url'])->assertOk();
        $this->assertDatabaseCount('canned_reply_images', 1);
        $ticket = $tickets->last();
        $ticket->messages()->sole()->forceFill(['delivery' => 'sent'])->save();
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', ['body' => $body])->assertOk();
        $email = new Email;
        $html = app(OutgoingMail::class)->html($ticket->messages()->reorder()->latest('id')->first(), $email);
        $this->assertSame(2, substr_count($html, '@relay.canned'));
        $this->assertCount(1, array_filter($email->getAttachments(), fn ($part) => $part->getFilename() === 'guide.png'));
    }

    public function test_canned_image_links_embed_existing_files_and_external_lookalike_urls_stay_external(): void
    {
        Storage::fake('local');
        config(['app.url' => 'https://helpdesk.areviewsapp.com']);
        $this->actingAs(User::factory()->create());
        $image = $this->post('/api/v1/canned-images', ['image' => UploadedFile::fake()->image('guide.png')], ['Accept' => 'application/json'])->assertCreated()->json();
        $url = config('app.url').$image['url'];
        $external = 'https://example.com'.$image['url'];
        $body = '![Guide](<'.$url.'>) ![External](<'.$external.'>)';
        $message = Message::factory()->create(['kind' => 'outbound', 'body' => $body]);
        $email = new Email;
        $html = app(OutgoingMail::class)->html($message, $email);
        $this->assertStringContainsString('src="cid:'.DB::table('canned_reply_images')->sole()->id.'@relay.canned"', $html);
        $this->assertStringContainsString('src="'.$external.'"', $html);
        $this->assertCount(0, app(CannedImages::class)->images('![External](<'.$external.'>)'));
        $this->assertDatabaseCount('canned_reply_images', 1);
    }

    public function test_canned_images_work_in_automated_replies(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $image = $this->post('/api/v1/canned-images', ['image' => UploadedFile::fake()->image('workflow.png')], ['Accept' => 'application/json'])->assertCreated()->json();
        $reply = CannedReply::factory()->create(['body' => 'Hi {{name}} '.$image['markdown']]);
        $ticket = Ticket::factory()->create();
        app(WorkflowActions::class)->apply($ticket, [['type' => 'reply_id', 'value' => $reply->id]], 'Welcome');
        $html = app(OutgoingMail::class)->html($ticket->messages()->sole(), new Email);
        $this->assertStringContainsString('@relay.canned', $html);
    }

    public function test_uploads_require_login_and_reject_unsafe_or_missing_images(): void
    {
        Storage::fake('local');
        $this->postJson('/api/v1/canned-images')->assertUnauthorized();
        $this->actingAs(User::factory()->create());
        $this->post('/api/v1/canned-images', ['image' => UploadedFile::fake()->createWithContent('evil.svg', '<svg onload="alert(1)"/>')], ['Accept' => 'application/json'])->assertUnprocessable();
        $payload = ['title' => 'Broken', 'category' => 'General', 'shortcut' => 'broken', 'body' => '![Image](/api/v1/canned-images/00000000-0000-0000-0000-000000000000)'];
        $this->postJson('/api/v1/manage/replies', $payload)->assertUnprocessable();
        $image = $this->post('/api/v1/canned-images', ['image' => UploadedFile::fake()->image('missing.png')], ['Accept' => 'application/json'])->assertCreated()->json();
        Storage::disk('local')->delete(DB::table('canned_reply_images')->sole()->path);
        $payload['body'] = $image['markdown'];
        $this->postJson('/api/v1/manage/replies', $payload)->assertUnprocessable();
        $ticket = Ticket::factory()->create();
        $this->postJson('/api/v1/tickets/'.$ticket->id.'/messages', ['body' => $image['markdown']])->assertUnprocessable();
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('canned_replies', 0);
    }
}
