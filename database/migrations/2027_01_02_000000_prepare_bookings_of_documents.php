<?php

use App\Support\Accounting\AccountingDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Bookings from invoices and payments (E1, 0.32.0). A cancellation fee is booked on its own income account, so its
 * invoice line says what it is; lines made before are recognised by their text. "Other income" becomes a system
 * account: invoices without an event are booked on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('invoice_lines', 'kind')) {
            Schema::table('invoice_lines', function (Blueprint $table) {
                $table->string('kind', 20)->nullable(); // null = what is charged, cancellation_fee
            });
            $this->markCancellationFees();
        }

        if (! DB::table('accounts')->where('system_key', 'other_income')->exists()) {
            $names = [trans('accounting.defaults.accounts.other_income', [], 'de'), trans('accounting.defaults.accounts.other_income', [], 'en')];
            $id = DB::table('accounts')->whereNull('system_key')->where('type', 'income')->whereIn('name', $names)->orderBy('id')->value('id');
            if ($id !== null) {
                DB::table('accounts')->where('id', $id)->update(['system_key' => 'other_income', 'updated_at' => now()]);
            }
        }
        AccountingDefaults::install();
    }

    /** Lines of cancelled registrations with the text of a cancellation fee, in either language. */
    private function markCancellationFees(): void
    {
        foreach (['de', 'en'] as $locale) {
            $prefix = explode(':name', (string) trans('finance.cancellation_fee', [], $locale))[0];
            if (trim($prefix) === '') {
                continue;
            }
            DB::table('invoice_lines')->whereNull('kind')->where('description', 'like', $prefix.'%')
                ->whereIn('participation_id', DB::table('participations')->where('status', 'cancelled')->select('id'))
                ->update(['kind' => 'cancellation_fee']);
        }
    }
};
