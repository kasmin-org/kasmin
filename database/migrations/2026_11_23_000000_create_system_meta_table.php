<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Key/value store for system information, e.g. the Kasmin version that last upgraded the database schema.
 * Migrations in Kasmin are upgrade-only; down() is intentionally not implemented.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_meta', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }
};
