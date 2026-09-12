<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('messages')->whereNotNull('author_email')->update(['author_email' => DB::raw('LOWER(TRIM(author_email))')]);
        Schema::table('messages', function (Blueprint $table) {
            $table->index(['author_email', 'kind', 'created_at'], 'messages_language_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('messages_language_lookup');
        });
    }
};
