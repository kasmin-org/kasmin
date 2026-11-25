<?php

use App\Support\Install\AfterMigrations;
use App\Support\Install\AutomationDefaults;
use App\Support\Install\EventTemplateDefaults;
use App\Support\Install\FormDefaults;
use App\Support\Install\MailTemplateDefaults;
use Illuminate\Database\Migrations\Migration;

/*
 * Installations that started with a version from before the mail templates never got the standard texts, so mails such
 * as the registration confirmation were not sent. Everything that is missing is installed once; nothing existing is
 * changed, and installations without standard content stay without.
 */
return new class extends Migration
{
    public function up(): void
    {
        AfterMigrations::request(FormDefaults::class, MailTemplateDefaults::class, AutomationDefaults::class, EventTemplateDefaults::class);
    }
};
