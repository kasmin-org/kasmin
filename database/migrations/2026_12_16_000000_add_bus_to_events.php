<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Travel by bus (ST06): an event can have a booked bus with a number of seats; every registration travels by bus
 * unless it is set to "car".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('bus_booked')->default(false);
            $table->unsignedInteger('bus_seats')->nullable();
        });
        Schema::table('participations', function (Blueprint $table) {
            $table->string('travel', 8)->default('bus');
        });
    }
};
