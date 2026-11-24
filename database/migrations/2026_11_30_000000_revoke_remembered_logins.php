<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Before 0.10.0, the limited login links in mails also created a "remember me" cookie with full access. All remembered
 * logins are revoked once; everybody logs in again with a fresh link.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->update(['remember_token' => null]);
        if (DB::getSchemaBuilder()->hasTable('sessions')) {
            DB::table('sessions')->delete();
        }
    }
};
