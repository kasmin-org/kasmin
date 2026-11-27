<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A discount is its own position on the invoice (B10): the line keeps the text of what is charged, the discount gets
 * its own text and (for siblings) the position of the child. Lines of invoices made before stay as they are – issued
 * invoices never change – and are shown as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('invoice_lines', 'discount_label')) {
                $table->string('discount_label', 200)->nullable();
            }
            if (! Schema::hasColumn('invoice_lines', 'discount_position')) {
                $table->unsignedTinyInteger('discount_position')->nullable();
            }
        });
    }
};
