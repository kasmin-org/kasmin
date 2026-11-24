<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The descriptions of the standard automations contained internal requirement numbers such as "(A05)".
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('automations')->where('is_standard', true)->whereNotNull('description')->get(['id', 'description']) as $row) {
            DB::table('automations')->where('id', $row->id)->update(['description' => preg_replace('/ \(A\d\d(\\\\?\/A\d\d)?\)\./', '.', (string) $row->description)]);
        }
    }
};
