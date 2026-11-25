<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency keys of API requests (X07), kept for one day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_token_id')->constrained()->cascadeOnDelete();
            $table->string('key_hash', 64);
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('status')->nullable(); // null = still running
            $table->longText('body')->nullable();               // encrypted
            $table->timestamps();
            $table->unique(['api_token_id', 'key_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_idempotency_keys');
    }
};
