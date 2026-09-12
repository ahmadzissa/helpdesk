<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_languages', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('language', 35)->nullable();
            $table->boolean('manual')->default(false);
            $table->foreignId('source_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('message_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->string('target_language', 35);
            $table->string('source_language', 35)->nullable();
            $table->string('source_hash', 64);
            $table->longText('body');
            $table->timestamps();
            $table->unique(['message_id', 'target_language']);
        });
        Schema::create('ticket_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->string('target_language', 35);
            $table->string('source_hash', 64);
            $table->text('subject');
            $table->timestamps();
            $table->unique(['ticket_id', 'target_language']);
        });
        Schema::table('messages', function (Blueprint $table) {
            $table->longText('original_body')->nullable();
            $table->text('translated_subject')->nullable();
            $table->json('translation_context')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['original_body', 'translated_subject', 'translation_context']);
        });
        Schema::dropIfExists('ticket_translations');
        Schema::dropIfExists('message_translations');
        Schema::dropIfExists('customer_languages');
    }
};
