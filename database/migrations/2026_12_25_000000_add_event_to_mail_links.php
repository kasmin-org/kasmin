<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Links in mails about an event stay valid until the event has ended (B14): the mail and the link remember the event.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['outgoing_mails', 'login_tokens'] as $name) {
            if (! Schema::hasColumn($name, 'event_id')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->unsignedBigInteger('event_id')->nullable()->index();
                });
            }
        }
    }
};
