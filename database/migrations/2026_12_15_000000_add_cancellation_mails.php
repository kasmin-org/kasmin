<?php

use App\Support\Install\AfterMigrations;
use App\Support\Install\MailTemplateDefaults;
use Illuminate\Database\Migrations\Migration;

/*
 * Mail templates for the confirmation of a cancellation and for an offer that ran out (CA01).
 */
return new class extends Migration
{
    public function up(): void
    {
        AfterMigrations::request(MailTemplateDefaults::class);
    }
};
