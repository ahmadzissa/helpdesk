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
            $table->timestamp('spammed_at')->nullable();
            $table->index(['folder', 'spammed_at']);
        });

        /** Historical Spam entry dates were not recorded, so grant existing tickets the full retention period. */
        DB::table('tickets')->where('folder', 'spam')->update(['spammed_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropIndex(['folder', 'spammed_at']);
            $table->dropColumn('spammed_at');
        });
    }
};
