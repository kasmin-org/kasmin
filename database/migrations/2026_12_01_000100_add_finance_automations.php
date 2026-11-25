<?php

use App\Support\Install\AfterMigrations;
use Illuminate\Database\Migrations\Migration;

/**
 * Payment reminders are an automation with approval now (DU02); it replaces the fixed schedule, so it exists in every
 * installation. The other finance automations are added too.
 */
return new class extends Migration
{
    public function up(): void
    {
        AfterMigrations::automations('payment_reminders', 'payment_followup', 'invoice_corrections', 'membership_invoices');
    }
};
