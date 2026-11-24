<?php

use App\Models\Automation;
use App\Support\Install\AutomationDefaults;
use Illuminate\Database\Migrations\Migration;

/**
 * Payment reminders are an automation with approval now (DU02); it replaces the fixed schedule, so it exists in every
 * installation. The other finance automations are added too.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['payment_reminders', 'payment_followup', 'invoice_corrections', 'membership_invoices'] as $key) {
            Automation::query()->firstOrCreate(['key' => $key], AutomationDefaults::definitions()[$key] + ['is_standard' => true, 'active' => false]);
        }
    }
};
