<?php

use App\Support\Accounting\AccountingDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Double-entry accounting (E1, 0.32.0): chart of accounts, cost centres (one over all years, active per period),
 * funds (pots of the equity), periods as date ranges, journal with lines, allocation keys, budgets, expense claims
 * and advances. Every installation gets the standard chart, a cost centre "General" and a fund "General fund".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounts')) {
            Schema::create('accounts', function (Blueprint $table) {
                $table->id();
                $table->string('number', 20)->nullable();
                $table->string('name', 120)->unique();
                $table->string('type', 16); // asset, liability, equity, income, expense
                $table->string('money', 8)->nullable(); // bank, cash
                $table->string('iban', 42)->nullable();
                $table->unsignedBigInteger('default_cost_center_id')->nullable()->index();
                $table->string('system_key', 32)->nullable()->unique();
                $table->boolean('active')->default(true);
                $table->unsignedInteger('sort')->default(0);
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('funds')) {
            Schema::create('funds', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120)->unique();
                $table->boolean('is_default')->default(false);
                $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete(); // bank or cash account to compare with
                $table->text('note')->nullable();
                $table->unsignedInteger('sort')->default(0);
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('cost_centers')) {
            Schema::create('cost_centers', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120)->unique();
                $table->boolean('is_default')->default(false);
                $table->boolean('carries')->default(true); // carries its result into a fund, or is allocated
                $table->foreignId('fund_id')->nullable()->constrained()->nullOnDelete();
                $table->unsignedInteger('sort')->default(0);
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('periods')) {
            Schema::create('periods', function (Blueprint $table) {
                $table->id();
                $table->string('name', 80);
                $table->date('starts_on')->unique();
                $table->date('ends_on')->unique();
                $table->string('status', 8)->default('open'); // open, closed
                $table->timestamp('closed_at')->nullable();
                $table->foreignId('closed_by_id')->nullable()->constrained('users')->nullOnDelete();
                $table->text('reopened_reason')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('cost_center_period')) {
            Schema::create('cost_center_period', function (Blueprint $table) {
                $table->foreignId('cost_center_id')->constrained()->cascadeOnDelete();
                $table->foreignId('period_id')->constrained()->cascadeOnDelete();
                $table->primary(['cost_center_id', 'period_id']);
            });
        }
        if (! Schema::hasTable('allocation_rules')) {
            Schema::create('allocation_rules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('period_id')->constrained()->cascadeOnDelete();
                $table->foreignId('source_cost_center_id')->constrained('cost_centers')->cascadeOnDelete();
                $table->foreignId('target_cost_center_id')->constrained('cost_centers')->cascadeOnDelete();
                $table->string('kind', 8); // fixed, percent, share, rest
                $table->unsignedBigInteger('value')->default(0); // cents (fixed) or basis points (percent)
                $table->unique(['period_id', 'source_cost_center_id', 'target_cost_center_id'], 'allocation_rules_unique');
            });
        }
        if (! Schema::hasTable('budgets')) {
            Schema::create('budgets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('period_id')->constrained()->cascadeOnDelete();
                $table->foreignId('cost_center_id')->constrained()->cascadeOnDelete();
                $table->foreignId('account_id')->nullable()->constrained()->cascadeOnDelete();
                $table->bigInteger('amount_cents'); // planned result: income positive, costs negative
                $table->unique(['period_id', 'cost_center_id', 'account_id']);
            });
        }
        if (! Schema::hasTable('journal_entries')) {
            Schema::create('journal_entries', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('number')->unique();
                $table->date('date')->index();
                $table->string('text', 250);
                $table->string('receipt', 120)->nullable();
                $table->string('receipt_path')->nullable();
                $table->string('kind', 16)->default('manual');
                $table->string('source_type', 40)->nullable();
                $table->unsignedBigInteger('source_id')->nullable();
                $table->foreignId('reverses_id')->nullable()->unique()->constrained('journal_entries')->nullOnDelete(); // one reversal per booking
                $table->foreignId('period_closing_id')->nullable()->constrained('periods')->nullOnDelete();
                $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['source_type', 'source_id', 'kind']);
            });
        }
        if (! Schema::hasTable('journal_lines')) {
            Schema::create('journal_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
                $table->foreignId('account_id')->constrained()->restrictOnDelete();
                $table->unsignedBigInteger('debit_cents')->default(0);
                $table->unsignedBigInteger('credit_cents')->default(0);
                $table->foreignId('cost_center_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('fund_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('person_id')->nullable()->constrained('people')->nullOnDelete();
                $table->string('text', 250)->nullable();
                $table->index(['account_id', 'cost_center_id']);
            });
        }
        if (! Schema::hasTable('advances')) {
            Schema::create('advances', function (Blueprint $table) {
                $table->id();
                $table->foreignId('person_id')->constrained('people')->restrictOnDelete();
                $table->bigInteger('amount_cents');
                $table->date('paid_on');
                $table->foreignId('account_id')->constrained()->restrictOnDelete(); // bank or cash paid from
                $table->string('purpose', 250)->nullable();
                $table->bigInteger('returned_cents')->default(0);
                $table->date('settled_on')->nullable();
                $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('expense_claims')) {
            Schema::create('expense_claims', function (Blueprint $table) {
                $table->id();
                $table->foreignId('person_id')->constrained('people')->restrictOnDelete();
                $table->foreignId('submitted_by_id')->nullable()->constrained('users')->nullOnDelete();
                $table->date('date');
                $table->bigInteger('amount_cents');
                $table->string('purpose', 250);
                $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('cost_center_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
                $table->string('receipt_path')->nullable();
                $table->boolean('flat')->default(false);
                $table->string('status', 12)->default('submitted'); // submitted, approved, rejected, paid, donated, settled
                $table->text('iban')->nullable(); // encrypted copy at the time of the payout
                $table->string('account_holder', 120)->nullable();
                $table->foreignId('advance_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('decided_at')->nullable();
                $table->text('reason')->nullable();
                $table->foreignId('paid_from_account_id')->nullable()->constrained('accounts')->nullOnDelete();
                $table->date('paid_on')->nullable();
                $table->timestamps();
                $table->index(['status', 'person_id']);
            });
        }
        foreach (['event_series' => ['cost_center_id'], 'events' => ['cost_center_id'], 'event_installments' => ['revenue_account_id', 'cost_center_id'], 'membership_types' => ['revenue_account_id', 'cost_center_id']] as $name => $columns) {
            foreach ($columns as $column) {
                if (! Schema::hasColumn($name, $column)) {
                    Schema::table($name, function (Blueprint $table) use ($column) {
                        $table->unsignedBigInteger($column)->nullable()->index();
                    });
                }
            }
        }
        if (! Schema::hasColumn('people', 'payout_iban')) {
            Schema::table('people', function (Blueprint $table) {
                $table->text('payout_iban')->nullable(); // encrypted; for refunds of expenses (E1d.3)
            });
        }

        AccountingDefaults::install();
    }
};
