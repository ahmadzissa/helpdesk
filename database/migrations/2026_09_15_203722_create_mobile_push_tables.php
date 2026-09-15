<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('principal_id')->index();
            $table->string('push_token', 255)->index();
            $table->string('session_hash', 64)->index();
            $table->uuid('binding_id');
            $table->unsignedInteger('auth_version')->nullable();
            $table->uuid('activity_version');
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('active_until')->nullable()->index();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('mobile_push_deliveries', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('event_id', 150);
            $table->uuid('device_id')->index();
            $table->uuid('binding_id');
            $table->string('type', 30);
            $table->string('record_id', 100);
            $table->uuid('condition')->nullable();
            $table->string('status', 20);
            $table->unsignedInteger('attempts')->default(0);
            $table->string('receipt_id', 255)->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamp('available_at');
            $table->timestamps();
            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_push_deliveries');
        Schema::dropIfExists('mobile_devices');
    }
};
