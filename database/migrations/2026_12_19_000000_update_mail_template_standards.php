<?php

use App\Support\Install\AfterMigrations;
use App\Support\Install\MailTemplateDefaults;
use Illuminate\Database\Migrations\Migration;

/*
 * New standard texts (event information without empty lines, names joined with "and"). Own texts stay as they are;
 * the template page offers the new standard.
 */
return new class extends Migration
{
    public function up(): void
    {
        AfterMigrations::request(MailTemplateDefaults::class);
    }
};
