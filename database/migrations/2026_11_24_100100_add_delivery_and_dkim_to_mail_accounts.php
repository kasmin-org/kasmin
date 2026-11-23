<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Direct delivery to the recipients' mail servers with DKIM signing (MS02, ST02). Upgrade-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_accounts', function (Blueprint $table) {
            $table->string('delivery', 8)->default('smtp')->after('name'); // smtp (relay) or direct (MX of the recipient)
            $table->string('dkim_domain')->nullable();
            $table->string('dkim_selector', 63)->nullable();
            $table->text('dkim_private_key')->nullable(); // encrypted, only for direct delivery
        });
    }
};
