<?php

use App\Support\Install\AfterMigrations;
use App\Support\Install\MailTemplateDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Order of the emergency contacts (SF10) and confirmation period per event (SF16). Upgrade-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guardianships', function (Blueprint $table) {
            $table->unsignedSmallInteger('emergency_sort')->nullable();
        });
        Schema::table('events', function (Blueprint $table) {
            $table->unsignedSmallInteger('safety_confirm_days')->nullable(); // confirmation not older than … days before the start
        });
        // New mail templates; existing ones are not changed. Installations without defaults stay without.
        if (DB::table('mail_templates')->where('key', 'safety_sheet_request')->exists()) {
            AfterMigrations::request(MailTemplateDefaults::class);
        }
    }
};
