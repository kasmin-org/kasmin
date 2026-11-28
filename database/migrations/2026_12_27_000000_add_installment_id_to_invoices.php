<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * An invoice refers to the instalment of the payment plan by its database id (B8); the key of the instalment is no
 * longer shown or typed anywhere. The id is filled in for the invoices made so far from the event and the key. Issued
 * invoices keep everything they say; installment_key stays, also for the invoices of membership fees
 * ("membership-<year>"), which belong to no instalment.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('invoices', 'installment_id')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->foreignId('installment_id')->nullable()->after('installment_key')->constrained('event_installments')->nullOnDelete();
            });
        }
        $installments = DB::table('event_installments')->select(['id', 'event_id', 'key'])->get()
            ->mapWithKeys(fn (object $i) => [$i->event_id.'|'.$i->key => (int) $i->id]);
        DB::table('invoices')->whereNull('installment_id')->whereNotNull('event_id')->whereNotNull('installment_key')
            ->select(['id', 'event_id', 'installment_key'])->orderBy('id')
            ->chunkById(500, function ($invoices) use ($installments): void {
                foreach ($invoices as $invoice) {
                    $id = $installments->get($invoice->event_id.'|'.$invoice->installment_key);
                    if ($id !== null) {
                        DB::table('invoices')->where('id', $invoice->id)->update(['installment_id' => $id]);
                    }
                }
            });
    }
};
