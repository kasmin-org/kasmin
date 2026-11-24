<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prices by age and date, reduced places, membership fees, cancellation rules, refunds, credits and split payments
 * (PR01, PR02, PR04, RF01–RF03, PY02, PY05, PY07).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('role_key', 32);
            $table->unsignedTinyInteger('min_age')->nullable();
            $table->unsignedTinyInteger('max_age')->nullable();
            $table->date('from_date')->nullable();  // date of the registration: early bird, late price
            $table->date('until_date')->nullable();
            $table->unsignedInteger('price_cents');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
        Schema::table('events', function (Blueprint $table) {
            $table->unsignedInteger('cancellation_fee_cents')->default(0);
            $table->json('cancellation_text')->nullable(); // shown before cancelling (PA03)
        });
        Schema::table('event_installments', function (Blueprint $table) {
            $table->unsignedSmallInteger('refund_until_days')->nullable(); // refundable until n days before the start; null = always
            $table->foreignId('membership_type_id')->nullable()->constrained()->nullOnDelete(); // kind "member"
            $table->boolean('confirms_place')->default(false);
            $table->foreignId('paid_add_group_id')->nullable()->constrained('groups')->nullOnDelete();
            $table->foreignId('paid_remove_group_id')->nullable()->constrained('groups')->nullOnDelete();
        });
        Schema::table('participations', function (Blueprint $table) {
            $table->unsignedInteger('price_cents')->nullable(); // reduced or free place
            $table->string('price_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
        });
        DB::table('participations')->where('status', 'cancelled')->update(['cancelled_at' => DB::raw('updated_at')]);
        Schema::table('membership_types', function (Blueprint $table) {
            $table->unsignedInteger('fee_cents')->default(0);
        });
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->foreignId('membership_type_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('period')->nullable(); // year of the membership fee
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('household_id')->nullable()->constrained()->nullOnDelete(); // credit of a family
            $table->foreignId('parent_id')->nullable()->constrained('payments')->nullOnDelete(); // part of a split transfer
        });
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payer_id')->nullable()->constrained('people')->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete(); // the credit that is paid back
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3)->default('EUR');
            $table->string('iban', 34)->nullable();
            $table->string('payer_name')->nullable();
            $table->string('reason');
            $table->string('status', 10)->default('proposed'); // proposed, done, declined
            $table->date('done_on')->nullable();
            $table->foreignId('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }
};
