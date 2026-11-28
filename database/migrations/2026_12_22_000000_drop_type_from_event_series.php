<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * An event series has only a name (B9). The type (camp, alumni, meeting, other) was never used: what kind of event it
 * is comes from the event template it is made from.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('event_series', 'type')) {
            Schema::table('event_series', function (Blueprint $table) {
                $table->dropColumn('type');
            });
        }
    }
};
