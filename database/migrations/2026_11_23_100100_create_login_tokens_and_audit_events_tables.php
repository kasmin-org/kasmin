<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Passwordless login: only hashes are stored, never the token or the code.
        Schema::create('login_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('code_hash', 64)->nullable();
            $table->string('purpose', 32); // login, mail_link
            $table->string('scope', 16)->default('full'); // full, limited
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->string('created_ip', 45)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'expires_at']);
        });

        // Append-only audit log (AU01–AU05).
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at')->useCurrent();
            $table->string('actor_type', 16); // user, system, automation, api
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 64);
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('changes')->nullable();
            $table->json('meta')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('request_id', 36)->nullable();
            $table->index(['subject_type', 'subject_id']);
            $table->index(['actor_type', 'actor_id']);
            $table->index('action');
        });
    }
};
