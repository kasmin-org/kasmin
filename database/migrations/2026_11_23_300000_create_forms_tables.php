<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Configurable person forms (safety sheet, general information): fields, versioned responses (values encrypted),
 * family-wide emergency contacts (SF01–SF24, FB06–FB08, FD01–FD02). Upgrade-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forms', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique(); // safety_sheet, general_information, …
            $table->json('name');
            $table->json('description')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedTinyInteger('applies_until_age')->nullable(); // safety sheet: not needed from this age (default 16)
            $table->unsignedSmallInteger('confirm_every_days')->nullable(); // yearly confirmation: 365
            $table->boolean('family_emergency_contacts')->default(false); // show the family's emergency contacts on top
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('form_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('type', 24);
            $table->json('label');
            $table->json('help')->nullable(); // tooltip / description
            $table->json('options')->nullable(); // choices with translations
            $table->boolean('required')->default(false);
            $table->string('sensitivity', 16)->default('normal'); // normal, health, identity
            $table->boolean('carry_over')->default(true);
            $table->json('list_use')->nullable(); // kitchen, medical, insurance, emergency
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->unique(['form_id', 'key']);
        });

        Schema::create('form_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->unsignedInteger('form_version');
            $table->longText('values'); // encrypted JSON
            $table->json('changed_keys')->nullable(); // fields changed compared with the previous version
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by_id')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamps();
            $table->index(['form_id', 'person_id', 'id']);
        });

        Schema::create('household_emergency_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('relation')->nullable();
            $table->string('phone');
            $table->string('note')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }
};
