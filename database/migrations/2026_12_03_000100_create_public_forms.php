<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public forms (PF01, PF03, PF04), applications (PA04a) and guests of participants (PA05).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_forms', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('kind', 16); // event, application, interest
            $table->json('title');
            $table->json('intro')->nullable();
            $table->foreignId('event_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('role_key', 32)->nullable();
            $table->foreignId('form_id')->nullable()->constrained()->nullOnDelete(); // questions of an application
            $table->foreignId('group_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('membership_type_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('needs_approval')->default(true);
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('closes_at')->nullable();
            $table->boolean('active')->default(false);
            $table->timestamps();
        });
        Schema::create('public_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('public_form_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->foreignId('participation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('status', 16)->default('unconfirmed'); // unconfirmed, pending, done, declined, failed
            $table->longText('data')->nullable(); // encrypted; removed once it is decided
            $table->string('note')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::table('events', function (Blueprint $table) {
            $table->unsignedTinyInteger('max_guests')->default(0);
        });
        Schema::table('participations', function (Blueprint $table) {
            $table->foreignId('guest_of_id')->nullable()->constrained('participations')->nullOnDelete();
        });
        Schema::create('guest_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participation_id')->constrained()->cascadeOnDelete();
            $table->string('email', 254)->nullable(); // removed when used or expired
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_invitations');
        Schema::table('participations', fn (Blueprint $table) => $table->dropConstrainedForeignId('guest_of_id'));
        Schema::table('events', fn (Blueprint $table) => $table->dropColumn('max_guests'));
        Schema::dropIfExists('public_submissions');
        Schema::dropIfExists('public_forms');
    }
};
