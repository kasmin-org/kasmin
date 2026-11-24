<?php

use App\Support\Install\AutomationDefaults;
use App\Support\Install\MailTemplateDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Registration rounds (RR01–RR03).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('role_key', 32);
            $table->string('kind', 16); // returning, selection, waitlist, open
            $table->string('mode', 16)->default('accept'); // accept, payment (RR02)
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('closes_at')->nullable();
            $table->unsignedTinyInteger('min_events')->default(0); // selection: events as a child in this series
            $table->foreignId('group_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
        Schema::table('participations', function (Blueprint $table) {
            $table->string('offer_mode', 16)->nullable(); // payment: paying the invoice accepts the place
            $table->string('offer_reason')->nullable();   // offered by an administrator (RR03)
        });
        if (DB::table('mail_templates')->where('is_standard', true)->exists()) {
            app(MailTemplateDefaults::class)->install();
            app(AutomationDefaults::class)->install();
        }
    }

    public function down(): void
    {
        Schema::table('participations', fn (Blueprint $table) => $table->dropColumn(['offer_mode', 'offer_reason']));
        Schema::dropIfExists('registration_rounds');
    }
};
