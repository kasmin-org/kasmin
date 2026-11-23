<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * System notifications for administrators (SN01): shown after login until dismissed, mailed once. Upgrade-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique(); // e.g. php_lowest:8.3, kasmin_update:0.6.0, cron_missing
            $table->string('level', 16); // info, warning, danger
            $table->json('title');
            $table->json('message');
            $table->string('url')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('system_notification_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('system_notification_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('mailed_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->unique(['system_notification_id', 'user_id']);
        });
    }
};
