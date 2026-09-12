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
        Schema::table('messages', function (Blueprint $table) {
            $table->mediumText('email_html')->nullable();
        });
        Schema::table('message_translations', function (Blueprint $table) {
            $table->string('body_format', 10)->default('text');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('email_html');
        });
        Schema::table('message_translations', function (Blueprint $table) {
            $table->dropColumn('body_format');
        });
    }
};
