<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automations (AT01–AT10): rules with trigger, action and execution mode; runs with a stored plan, approvals by
 * e-mail or in the approvals inbox; per-subject counters for repeated reminders. Waitlist offers get a deadline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automations', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->json('name');
            $table->json('description')->nullable();
            $table->string('action', 32);            // built-in action, see App\Support\Automations\Engine::ACTIONS
            $table->json('config')->nullable();      // parameters of the action (template, days, statuses …)
            $table->string('trigger', 32);           // manual, daily, before_event, after_event, on:<event>
            $table->json('trigger_config')->nullable(); // time, days
            $table->string('mode', 12)->default('confirm'); // automatic, confirm, manual (AT02)
            $table->json('approver_roles')->nullable();     // default: board, administrator
            $table->unsignedInteger('max_items')->default(200); // above this, even automatic runs need approval (AT10)
            $table->boolean('active')->default(false);
            $table->boolean('is_standard')->default(false);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });

        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_id')->constrained()->cascadeOnDelete();
            $table->string('dedupe_key', 120)->nullable();
            $table->string('status', 20); // pending_approval, approved, denied, expired, done, failed
            $table->string('trigger', 64);
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->json('plan');           // items: key, label, details
            $table->json('excluded')->nullable();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->foreignId('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('comment', 500)->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();
            $table->unique(['automation_id', 'dedupe_key']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('automation_approval_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('automation_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_id')->constrained()->cascadeOnDelete();
            $table->string('subject', 120); // e.g. "event:12:household:5"
            $table->unsignedInteger('count')->default(0);
            $table->timestamp('last_at')->nullable();
            $table->timestamps();
            $table->unique(['automation_id', 'subject']);
        });

        Schema::table('participations', function (Blueprint $table) {
            $table->timestamp('offer_expires_at')->nullable()->after('reserve_reason');
        });
    }
};
