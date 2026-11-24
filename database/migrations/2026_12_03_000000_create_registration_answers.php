<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Questions of an event (FB01–FB05): forms of the kind "registration", asked per participant or once per family.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->string('kind', 16)->default('person'); // person, registration
        });
        Schema::table('form_fields', function (Blueprint $table) {
            $table->string('scope', 16)->default('participant'); // participant, family
        });
        Schema::table('event_roles', function (Blueprint $table) {
            $table->foreignId('registration_form_id')->nullable()->constrained('forms')->nullOnDelete();
        });
        Schema::table('events', function (Blueprint $table) {
            $table->date('answers_until')->nullable(); // answers can be changed until this day; null = start of the event
        });
        Schema::create('registration_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->foreignId('participation_id')->nullable()->constrained()->cascadeOnDelete(); // per participant
            $table->foreignId('household_id')->nullable()->constrained()->cascadeOnDelete();     // once per family
            $table->unsignedInteger('form_version');
            $table->json('values');                     // not confidential
            $table->longText('confidential')->nullable(); // encrypted
            $table->json('consents')->nullable();       // text of the consents as it was shown
            $table->foreignId('answered_by_id')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamps();
            $table->unique(['participation_id', 'form_id']);
            $table->unique(['event_id', 'household_id', 'form_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_answers');
        Schema::table('events', fn (Blueprint $table) => $table->dropColumn('answers_until'));
        Schema::table('event_roles', fn (Blueprint $table) => $table->dropConstrainedForeignId('registration_form_id'));
        Schema::table('form_fields', fn (Blueprint $table) => $table->dropColumn('scope'));
        Schema::table('forms', fn (Blueprint $table) => $table->dropColumn('kind'));
    }
};
