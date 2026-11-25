<?php

use App\Support\Install\AutomationDefaults;
use App\Support\Install\MailTemplateDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Waitlists: order by rules, flags, skipping, reserve places and waitlists of groups (WL01, WL02, WL06, AD31).
 * Upgrade-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_roles', function (Blueprint $table) {
            $table->json('waitlist_order')->nullable(); // criteria in order; empty = registration date
        });
        Schema::table('participations', function (Blueprint $table) {
            $table->json('waitlist_flags')->nullable();
            $table->string('waitlist_note', 250)->nullable();
            $table->timestamp('skipped_at')->nullable();
            $table->string('skip_reason', 250)->nullable();
        });
        Schema::table('events', function (Blueprint $table) {
            $table->date('reserve_reminder_on')->nullable();
            $table->boolean('reserve_auto_release')->default(false);
            $table->timestamp('reserve_reminded_at')->nullable();
        });
        Schema::table('groups', function (Blueprint $table) {
            $table->boolean('has_waitlist')->default(false);
            $table->unsignedInteger('capacity')->nullable();
            $table->json('waitlist_order')->nullable();
        });
        Schema::create('group_waitlist_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('status', 16)->default('waiting'); // waiting, offered, joined, declined, removed
            $table->timestamp('listed_at')->useCurrent();
            $table->json('waitlist_flags')->nullable();
            $table->string('waitlist_note', 250)->nullable();
            $table->timestamp('skipped_at')->nullable();
            $table->string('skip_reason', 250)->nullable();
            $table->timestamp('offer_expires_at')->nullable();
            $table->timestamps();
            $table->index(['group_id', 'status']);
        });
        if (DB::table('mail_templates')->where('is_standard', true)->exists()) {
            app(MailTemplateDefaults::class)->install();
            app(AutomationDefaults::class)->install();
        }
    }
};
