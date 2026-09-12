<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('merged_into_id')->nullable()->constrained('tickets')->restrictOnDelete();
            $table->unsignedBigInteger('event_version')->default(0);
        });
        Schema::table('automations', function (Blueprint $table) {
            $table->string('repeat_mode')->default('once');
            $table->unsignedInteger('interval_minutes')->default(60);
            $table->unsignedInteger('max_runs')->default(100);
        });
        Schema::table('automation_runs', function (Blueprint $table) {
            $table->dropUnique(['automation_id', 'ticket_id']);
            $table->string('run_key', 80)->default('once');
            $table->unique(['automation_id', 'ticket_id', 'run_key']);
        });
        Schema::create('macros', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->boolean('enabled')->default(true);
            $table->json('actions');
            $table->timestamps();
        });
        Schema::create('macro_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('macro_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('request_id');
            $table->timestamp('created_at');
            $table->unique(['user_id', 'request_id']);
        });
        Schema::create('follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->string('status_after')->nullable();
            $table->timestamp('due_at');
            $table->boolean('cancel_on_reply')->default(true);
            $table->unsignedBigInteger('inbound_message_id')->default(0);
            $table->string('state')->default('pending');
            $table->string('result')->nullable();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['state', 'due_at']);
        });
        Schema::create('sender_rules', function (Blueprint $table) {
            $table->id();
            $table->string('kind');
            $table->string('value');
            $table->string('action');
            $table->boolean('include_subdomains')->default(false);
            $table->timestamps();
            $table->unique(['kind', 'value']);
        });
        Schema::create('recipient_suppressions', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('reason');
            $table->string('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('inline_images', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('name');
            $table->string('mime');
            $table->timestamp('created_at');
        });
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mailbox_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('prefix', 20);
            $table->string('token_hash', 64)->unique();
            $table->json('scopes');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('api_ticket_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_key_id')->constrained()->cascadeOnDelete();
            $table->string('request_key', 100);
            $table->string('request_hash', 64);
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->unique(['api_key_id', 'request_key']);
        });
        Schema::table('mail_delivery_attempts', function (Blueprint $table) {
            $table->json('recipients')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('opened_at')->nullable();
        });
        Schema::create('delivery_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('attempt_id');
            $table->foreign('attempt_id')->references('id')->on('mail_delivery_attempts')->cascadeOnDelete();
            $table->string('event_key', 150)->unique();
            $table->string('type');
            $table->string('recipient')->nullable();
            $table->string('detail')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_events');
        Schema::table('mail_delivery_attempts', fn (Blueprint $table) => $table->dropColumn(['recipients', 'sent_at', 'delivered_at', 'opened_at']));
        foreach (['api_ticket_requests', 'api_keys', 'inline_images', 'recipient_suppressions', 'sender_rules', 'follow_ups', 'macro_runs', 'macros'] as $name) {
            Schema::dropIfExists($name);
        }
        Schema::table('automation_runs', fn (Blueprint $table) => $table->dropUnique(['automation_id', 'ticket_id', 'run_key']));
        // Keep historical runs on rollback; the old application checks the pair before acting.
        Schema::table('automation_runs', fn (Blueprint $table) => $table->dropColumn('run_key'));
        Schema::table('automations', fn (Blueprint $table) => $table->dropColumn(['repeat_mode', 'interval_minutes', 'max_runs']));
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merged_into_id');
            $table->dropColumn('event_version');
        });
    }
};
