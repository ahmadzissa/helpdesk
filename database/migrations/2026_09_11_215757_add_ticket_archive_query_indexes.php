<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->index(['folder', 'merged_into_id', 'last_activity_at'], 'tickets_folder_activity_index');
            $table->index(['folder', 'merged_into_id', 'mailbox_id', 'last_activity_at'], 'tickets_mailbox_activity_index');
            $table->index('merged_into_id');
        });
        Schema::table('messages', function (Blueprint $table): void {
            $table->index(['ticket_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropIndex(['ticket_id', 'id']);
        });
        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropIndex('tickets_folder_activity_index');
            $table->dropIndex('tickets_mailbox_activity_index');
            $table->dropIndex(['merged_into_id']);
        });
    }
};
