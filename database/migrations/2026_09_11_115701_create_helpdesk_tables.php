<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('agent');
            $table->json('preferences')->nullable();
        });
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });
        Schema::create('mailboxes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->string('color')->default('#7450bb');
            $table->string('smtp_host')->nullable();
            $table->unsignedInteger('smtp_port')->default(587);
            $table->string('smtp_encryption')->default('tls');
            $table->string('smtp_username')->nullable();
            $table->text('smtp_password')->nullable();
            $table->string('imap_host')->nullable();
            $table->unsignedInteger('imap_port')->default(993);
            $table->string('imap_username')->nullable();
            $table->text('imap_password')->nullable();
            $table->boolean('sending_enabled')->default(false);
            $table->timestamps();
        });
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('subject');
            $table->string('requester_name')->nullable();
            $table->string('requester_email')->index();
            $table->string('company')->nullable();
            $table->string('status')->default('Open')->index();
            $table->string('priority')->default('Normal');
            $table->string('folder')->default('inbox')->index();
            $table->string('source')->default('Email');
            $table->foreignId('mailbox_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('tags')->nullable();
            $table->json('cc')->nullable();
            $table->json('custom_fields')->nullable();
            $table->boolean('unread')->default(true);
            $table->timestamp('last_activity_at')->index();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['folder', 'status', 'mailbox_id']);
        });
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind')->default('inbound');
            $table->string('author_name')->nullable();
            $table->string('author_email')->nullable();
            $table->text('body');
            $table->string('delivery')->nullable();
            $table->string('delivery_error')->nullable();
            $table->string('rule_name')->nullable();
            $table->json('attachments')->nullable();
            $table->timestamps();
        });
        Schema::create('saved_views', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('tag')->nullable();
            $table->json('filters')->nullable();
            $table->timestamps();
        });
        Schema::create('canned_replies', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('shortcut')->unique();
            $table->string('category')->default('General');
            $table->text('body');
            $table->timestamps();
        });
        Schema::create('automations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('enabled')->default(false);
            $table->string('trigger')->default('ticket.created');
            $table->json('conditions');
            $table->json('actions');
            $table->timestamps();
        });
        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at');
            $table->unique(['automation_id', 'ticket_id']);
        });
        Schema::create('workspace_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value');
            $table->timestamps();
        });
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->timestamps();
        });
        Schema::create('ticket_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body')->nullable();
            $table->boolean('private')->default(false);
            $table->timestamps();
            $table->unique(['ticket_id', 'user_id']);
        });
    }

    public function down(): void
    {
        foreach (['ticket_drafts', 'activities', 'workspace_settings', 'automation_runs', 'automations', 'canned_replies', 'saved_views', 'messages', 'tickets', 'mailboxes', 'teams'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['role', 'preferences']));
    }
};
