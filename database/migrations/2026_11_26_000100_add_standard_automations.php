<?php

use App\Support\Install\AfterMigrations;
use App\Support\Install\AutomationDefaults;
use App\Support\Install\MailTemplateDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the standard automations (switched off) and the waitlist offer template (0.7.0) to installations that use
 * the standard content. Nothing existing is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('mail_templates')->where('is_standard', true)->exists()) {
            AfterMigrations::request(MailTemplateDefaults::class);
            AfterMigrations::request(AutomationDefaults::class);
        }
    }
};
