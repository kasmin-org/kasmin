<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * One reversal per booking also in databases that ran a development version of the accounting before the index was
 * part of its table (0.32.0 creates it with the table).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('journal_entries') || Schema::hasIndex('journal_entries', ['reverses_id'], 'unique')) {
            return;
        }
        if (DB::table('journal_entries')->whereNotNull('reverses_id')->groupBy('reverses_id')->havingRaw('COUNT(*) > 1')->exists()) {
            return; // two reversals of one booking already: left for a person to look at, not failed here
        }
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->unique('reverses_id');
        });
    }
};
