<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Roles that only count for the own events (RO01). Camp leaders and counsellors are restricted to their events, also in
 * existing installations. Upgrade-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('scope', 8)->default('all'); // all, own
        });
        Schema::table('role_assignments', function (Blueprint $table) {
            // Deleting the event must never turn the assignment into one for all events.
            $table->foreignId('event_id')->nullable()->constrained()->cascadeOnDelete();
        });
        DB::table('roles')->whereIn('key', ['camp_leader', 'counsellor'])->update(['scope' => 'own']);
    }
};
