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
            $table->timestamp('trashed_at')->nullable();
            $table->index(['folder', 'trashed_at']);
        });

        /** Historical Trash entry dates were not recorded, so grant existing tickets the full retention period. */
        DB::table('tickets')->where('folder', 'trash')->update(['trashed_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropIndex(['folder', 'trashed_at']);
            $table->dropColumn('trashed_at');
        });
    }
};
