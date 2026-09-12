<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->boolean('incoming_enabled')->default(false);
            $table->string('imap_encryption')->default('ssl');
            $table->timestamp('last_synced_at')->nullable();
            $table->string('sync_status')->default('idle');
            $table->string('sync_error')->nullable();
        });
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('mailbox_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_id')->nullable();
            $table->unique(['mailbox_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique(['mailbox_id', 'external_id']);
            $table->dropConstrainedForeignId('mailbox_id');
            $table->dropColumn('external_id');
        });
        Schema::table('mailboxes', fn (Blueprint $table) => $table->dropColumn(['incoming_enabled', 'imap_encryption', 'last_synced_at', 'sync_status', 'sync_error']));
    }
};
