<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Invoices from the time before Kasmin (MI07): kept to look them up, never changed, with their PDF.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archived_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number', 60)->unique();
            $table->date('issued_on')->nullable();
            $table->string('payer_name', 200)->nullable();
            $table->foreignId('person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->bigInteger('total_cents')->default(0);
            $table->string('currency', 3)->default('EUR');
            $table->string('status', 16)->default('paid'); // paid, open, cancelled
            $table->date('paid_on')->nullable();
            $table->string('note', 250)->nullable();
            $table->string('pdf_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archived_invoices');
    }
};
