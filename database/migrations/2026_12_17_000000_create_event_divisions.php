<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Groups within an event (EC04): a division (e.g. "Groups", "Rooms", "Kitchen duty") has units, and every person who
 * takes part is in at most one unit per division.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_divisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('kind', 16); // groups, rooms, duty
            $table->json('options')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
        Schema::create('event_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_division_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->unsignedInteger('capacity')->nullable();
            $table->string('note', 250)->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
        Schema::create('event_unit_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_division_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('participation_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['event_division_id', 'participation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_unit_members');
        Schema::dropIfExists('event_units');
        Schema::dropIfExists('event_divisions');
    }
};
