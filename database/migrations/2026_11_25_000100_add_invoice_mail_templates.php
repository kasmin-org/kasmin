<?php

use App\Support\Install\AfterMigrations;
use App\Support\Install\MailTemplateDefaults;
use Illuminate\Database\Migrations\Migration;

/**
 * Adds the invoice and payment templates (0.6.0) to installations that use the standard templates. Existing
 * templates are never changed; installations without defaults stay without.
 */
return new class extends Migration
{
    public function up(): void
    {
        AfterMigrations::request(MailTemplateDefaults::class);
    }
};
