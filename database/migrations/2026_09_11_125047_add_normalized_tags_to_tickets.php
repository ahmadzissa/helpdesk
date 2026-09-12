<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', fn (Blueprint $table) => $table->json('normalized_tags')->nullable());
        DB::table('tickets')->orderBy('id')->chunkById(100, function ($tickets) {
            foreach ($tickets as $ticket) {
                $tags = json_decode($ticket->tags ?? '[]', true) ?: [];
                DB::table('tickets')->where('id', $ticket->id)->update(['normalized_tags' => json_encode(array_map(fn ($tag) => mb_strtolower($tag), $tags))]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('tickets', fn (Blueprint $table) => $table->dropColumn('normalized_tags'));
    }
};
