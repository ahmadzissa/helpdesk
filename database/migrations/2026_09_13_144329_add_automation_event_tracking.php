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
        Schema::table('tickets', function (Blueprint $table): void {
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamp('workflow_activity_at')->nullable();
        });
        Schema::table('messages', function (Blueprint $table): void {
            $table->timestamp('sent_at')->nullable();
        });
        Schema::table('follow_ups', function (Blueprint $table): void {
            $table->foreignId('automation_id')->nullable()->constrained()->nullOnDelete();
        });
        Schema::table('automations', function (Blueprint $table): void {
            $table->text('description')->nullable();
        });
        DB::table('tickets')->update(['workflow_activity_at' => DB::raw('last_activity_at')]);
        DB::table('mail_delivery_attempts')->whereNotNull('sent_at')->orderBy('id')->chunkById(500, function ($attempts): void {
            foreach ($attempts as $attempt) {
                DB::table('messages')->where('id', $attempt->message_id)->whereNull('sent_at')->update(['sent_at' => $attempt->sent_at]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('automations', fn (Blueprint $table) => $table->dropColumn('description'));
        Schema::table('follow_ups', fn (Blueprint $table) => $table->dropConstrainedForeignId('automation_id'));
        Schema::table('messages', fn (Blueprint $table) => $table->dropColumn('sent_at'));
        Schema::table('tickets', fn (Blueprint $table) => $table->dropColumn(['status_changed_at', 'workflow_activity_at']));
    }
};
