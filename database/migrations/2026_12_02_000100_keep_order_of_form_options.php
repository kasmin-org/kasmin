<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MySQL sorts the keys of JSON columns, so the order of choices and sub-fields was lost there. Text keeps it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_fields', function (Blueprint $table) {
            $table->longText('options')->nullable()->change();
        });
    }
};
