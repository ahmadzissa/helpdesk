<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->timestamp('import_started_at')->nullable();
            $table->unsignedBigInteger('imap_uidvalidity')->nullable();
            $table->unsignedBigInteger('imap_last_uid')->default(0);
        });
        DB::table('mailboxes')->update(['import_started_at' => DB::raw('created_at')]);
        Schema::create('mail_import_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('mailbox_key', 64);
            $table->string('message_key', 64);
            $table->timestamp('created_at');
            $table->unique(['mailbox_key', 'message_key']);
        });
        DB::table('messages')->join('mailboxes', 'mailboxes.id', '=', 'messages.mailbox_id')
            ->where('messages.kind', 'inbound')->whereNotNull('messages.external_id')
            ->select('messages.id', 'messages.external_id', 'messages.created_at', 'mailboxes.email')
            ->chunkById(500, function ($messages): void {
                DB::table('mail_import_receipts')->insertOrIgnore($messages->map(fn ($message) => [
                    'mailbox_key' => hash('sha256', mb_strtolower(trim($message->email))),
                    'message_key' => hash('sha256', $message->external_id), 'created_at' => $message->created_at,
                ])->all());
            }, 'messages.id', 'id');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mail_import_receipts');
        Schema::table('mailboxes', fn (Blueprint $table) => $table->dropColumn(['import_started_at', 'imap_uidvalidity', 'imap_last_uid']));
    }
};
