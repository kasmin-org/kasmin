<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Issued invoices never change their look (B10): invoices made before discounts became their own position keep the
 * table with the discount column, also when they have no discount. Kasmin remembers the first invoice id after the
 * update; a new installation starts at 1, so every invoice there has the new look.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('system_meta')->where('key', 'invoice_discount_lines_from')->exists()) {
            DB::table('system_meta')->insert([
                'key' => 'invoice_discount_lines_from', 'value' => (string) (((int) DB::table('invoices')->max('id')) + 1),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
};
