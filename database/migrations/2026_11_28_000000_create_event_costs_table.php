<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cost items per event (AD64) – entered by hand until an accounting adapter exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('label', 200);
            $table->string('category', 32)->nullable(); // accommodation, food, transport, material, other
            $table->integer('amount_cents');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
    }
};
