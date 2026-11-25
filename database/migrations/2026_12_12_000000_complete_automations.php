<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Own automations, several approvals, runs about one person (AT01, RO03). Upgrade-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automations', function (Blueprint $table) {
            $table->unsignedTinyInteger('approvals_needed')->default(1);
        });
        Schema::table('automation_runs', function (Blueprint $table) {
            $table->json('context')->nullable(); // person and registration that started the run
        });
        Schema::create('automation_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('excluded')->nullable();
            $table->timestamps();
            $table->unique(['automation_run_id', 'user_id']);
        });
    }
};
