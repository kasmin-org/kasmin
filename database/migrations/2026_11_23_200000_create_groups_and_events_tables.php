<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Groups (GR), event series and events (ET/EE), capacities and age rules per participant role, participations
 * with status and waitlist (PA/WL). Upgrade-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('groups')->nullOnDelete();
            $table->string('kind', 16)->default('manual'); // manual, dynamic, event
            $table->json('filter')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('group_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('source', 64)->nullable(); // manual, registration form, automation …
            $table->timestamps();
            $table->index(['group_id', 'person_id']);
        });

        Schema::create('event_series', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 32)->default('camp'); // camp, alumni, meeting, other
            $table->timestamps();
        });

        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('event_series_id')->nullable()->constrained('event_series')->nullOnDelete();
            $table->string('title');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('location')->nullable();
            $table->string('meeting_point')->nullable();
            $table->text('description')->nullable();
            $table->string('status', 16)->default('draft'); // draft, announced, open, closed, running, finished, archived
            $table->boolean('registration_binding')->default(true); // false: registrations are requests to be confirmed
            $table->date('age_reference_date')->nullable(); // null = event start
            $table->timestamps();
        });

        Schema::create('event_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('role_key', 32); // child, junior_counsellor, staff, guest
            $table->unsignedInteger('capacity')->nullable(); // null = unlimited
            $table->unsignedInteger('reserve_places')->default(0);
            $table->unsignedTinyInteger('min_age')->nullable();
            $table->unsignedTinyInteger('max_age')->nullable();
            $table->string('registered_by', 16)->default('guardian'); // guardian, self, admin
            $table->timestamps();
            $table->unique(['event_id', 'role_key']);
        });

        Schema::create('participations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('role_key', 32);
            $table->string('status', 16); // requested, registered, confirmed, waitlist, offered, cancelled, declined, attended, no_show
            $table->timestamp('registered_at');
            $table->foreignId('registered_by_id')->nullable()->constrained('people')->nullOnDelete();
            $table->boolean('reserve_place')->default(false);
            $table->string('reserve_reason')->nullable();
            $table->timestamps();
            $table->unique(['event_id', 'person_id', 'role_key']);
            $table->index(['event_id', 'role_key', 'status']);
        });
    }
};
