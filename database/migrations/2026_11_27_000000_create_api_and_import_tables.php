<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 0.8: API tokens with scopes (X03), webhooks with delivery log (AD81), import batches with rollback and the
 * source IDs of imported records for idempotent re-imports (MI02–MI06).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // acts on behalf of this user
            $table->string('name', 120);
            $table->string('token_hash', 64)->unique();
            $table->string('prefix', 12);           // first characters, to recognise a token in lists
            $table->json('scopes');
            $table->boolean('mcp')->default(false); // may be used by AI assistants via MCP
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('url', 500);
            $table->json('events');
            $table->text('secret'); // encrypted
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('event', 64);
            $table->json('payload');
            $table->string('status', 12)->default('pending'); // pending, sent, failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_attempt_at']);
        });

        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('source', 64);    // e.g. csv, webling, api
            $table->string('kind', 32);      // people, participations
            $table->string('status', 16);    // imported, rolled_back
            $table->json('stats')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('api_token_id')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();
        });

        Schema::create('import_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained()->cascadeOnDelete();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->string('action', 10); // created, updated
            $table->json('before')->nullable();
            $table->index(['model_type', 'model_id']);
        });

        Schema::create('external_ids', function (Blueprint $table) {
            $table->id();
            $table->string('source', 64);
            $table->string('source_id', 191);
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->timestamps();
            $table->unique(['source', 'source_id', 'model_type']);
            $table->index(['model_type', 'model_id']);
        });
    }
};
