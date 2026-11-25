<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Bad addresses (MS05) and the standard a mail template was made from (MT01). Upgrade-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_points', function (Blueprint $table) {
            $table->timestamp('bounced_at')->nullable()->index();
            $table->string('bounce_reason', 500)->nullable();
        });
        Schema::table('mail_templates', function (Blueprint $table) {
            $table->string('standard_hash', 64)->nullable(); // shipped text this template was compared with last
        });
    }
};
