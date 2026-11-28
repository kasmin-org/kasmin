<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Expense claims (E1d.3): which flat rate a claim uses (a flat rate is claimed once per person and event), whether the
 * person waives the refund already when submitting (it becomes a donation at the approval), and the claim that holds
 * what the organisation still owes after an advance was settled.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['flat_rate' => fn (Blueprint $t) => $t->string('flat_rate', 80)->nullable(),
            'waived' => fn (Blueprint $t) => $t->boolean('waived')->default(false),
            'advance_rest' => fn (Blueprint $t) => $t->boolean('advance_rest')->default(false)] as $column => $add) {
            if (! Schema::hasColumn('expense_claims', $column)) {
                Schema::table('expense_claims', function (Blueprint $table) use ($add) {
                    $add($table);
                });
            }
        }
    }
};
