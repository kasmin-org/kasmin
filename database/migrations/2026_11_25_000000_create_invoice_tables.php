<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Prices, payment plans, sibling and role discounts, invoices with lines, payments, bank imports, reminders
 * (PR/SD/IN/PY/DU/RF). Amounts in cents. Upgrade-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_roles', function (Blueprint $table) {
            $table->unsignedInteger('price_cents')->default(0);
            $table->unsignedInteger('role_discount_cents')->default(0); // e.g. junior counsellors; 0 = none (SD09)
            $table->boolean('counts_as_sibling')->default(true);
        });

        Schema::table('events', function (Blueprint $table) {
            $table->string('currency', 3)->default('EUR');
            $table->string('sibling_mode', 12)->default('none'); // none, staggered, all (SD01–SD03)
            $table->unsignedInteger('sibling_amount_cents')->default(0);
            $table->unsignedTinyInteger('sibling_cap_position')->nullable(); // staggered: discount stops growing here
            $table->unsignedTinyInteger('sibling_min_children')->default(2); // mode "all"
            $table->string('sibling_order', 16)->default('oldest'); // oldest, highest_price, registration
            $table->json('reminder_days_before')->nullable(); // default [3] (DU01)
        });

        // Payment plan per event (PR03): e.g. deposit (fixed, not refundable) + balance (rest, discounted).
        Schema::create('event_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('key', 32);
            $table->json('label');
            $table->string('kind', 8); // fixed, rest
            $table->unsignedInteger('amount_cents')->nullable(); // fixed amount per participant
            $table->date('due_date')->nullable();
            $table->unsignedSmallInteger('due_days')->nullable(); // days after issuing
            $table->boolean('refundable')->default(true);
            $table->boolean('discounts_apply')->default(false); // sibling/role discounts are applied here (SD06)
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->unique(['event_id', 'key']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number')->nullable()->unique(); // set when issued (IN03)
            $table->string('type', 12)->default('invoice'); // invoice, credit_note
            $table->foreignId('household_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payer_id')->nullable()->constrained('people')->nullOnDelete();
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->string('installment_key', 32)->nullable();
            $table->string('status', 16)->default('draft'); // draft, issued, partially_paid, paid, cancelled, corrected
            $table->string('currency', 3)->default('EUR');
            $table->integer('total_cents')->default(0);
            $table->integer('paid_cents')->default(0);
            $table->string('reference')->nullable()->index(); // structured creditor reference (RF…)
            $table->date('issued_on')->nullable();
            $table->date('due_on')->nullable();
            $table->string('payer_name')->nullable();
            $table->text('payer_address')->nullable();
            $table->foreignId('corrects_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('participation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->string('description');
            $table->integer('amount_cents'); // after discount
            $table->integer('discount_cents')->default(0);
            $table->string('discount_kind', 12)->nullable(); // sibling, role
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('bank_imports', function (Blueprint $table) {
            $table->id();
            $table->string('account')->nullable();
            $table->string('file_name');
            $table->json('mapping');
            $table->unsignedInteger('rows')->default(0);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('amount_cents');
            $table->date('paid_on');
            $table->string('method', 12)->default('bank'); // bank, cash, other
            $table->string('reference')->nullable();
            $table->string('payer_name')->nullable();
            $table->string('iban', 34)->nullable();
            $table->foreignId('bank_import_id')->nullable()->constrained()->nullOnDelete();
            $table->string('hash', 64)->nullable()->unique(); // duplicate detection of bank rows (PY01)
            $table->string('match', 12)->default('manual'); // auto, suggested, manual, unmatched
            $table->foreignId('suggested_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('invoice_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16); // before_due, overdue
            $table->smallInteger('days'); // days before (positive) or after (negative) the due date
            $table->timestamp('sent_at');
            $table->unique(['invoice_id', 'kind', 'days']);
        });
    }
};
