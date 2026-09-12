<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_safety', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('threshold')->default(5);
            $table->unsignedInteger('window_minutes')->default(15);
            $table->unsignedInteger('epoch')->default(0);
            $table->timestamp('paused_at')->nullable();
            $table->string('reason')->nullable();
        });
        DB::table('mail_safety')->insert(['id' => 1]);
        Schema::table('messages', function (Blueprint $table) {
            $table->unsignedInteger('sending_epoch')->default(0);
            $table->uuid('attempt_id')->nullable();
            $table->index('delivery');
        });
        Schema::create('mail_delivery_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('mailbox_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_id');
            $table->string('recipient');
            $table->timestamp('created_at');
            $table->timestamp('failed_at')->nullable();
            $table->unsignedInteger('failure_epoch')->nullable();
            $table->string('failure_kind')->nullable();
            $table->string('error')->nullable();
            $table->unique(['mailbox_id', 'external_id']);
            $table->index(['failure_epoch', 'failed_at']);
        });
        // Preserve correlation for replies sent before bounce protection was installed.
        DB::table('messages')->where('kind', 'outbound')->whereNotNull('external_id')->whereNotNull('mailbox_id')->orderBy('id')->chunkById(100, function ($messages) {
            foreach ($messages as $message) {
                $id = (string) Str::uuid();
                DB::table('mail_delivery_attempts')->insert(['id' => $id, 'message_id' => $message->id, 'mailbox_id' => $message->mailbox_id,
                    'external_id' => $message->external_id, 'recipient' => DB::table('tickets')->where('id', $message->ticket_id)->value('requester_email'), 'created_at' => $message->created_at]);
                DB::table('messages')->where('id', $message->id)->update(['attempt_id' => $id]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_delivery_attempts');
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['delivery']);
            $table->dropColumn(['sending_epoch', 'attempt_id']);
        });
        Schema::dropIfExists('mail_safety');
    }
};
