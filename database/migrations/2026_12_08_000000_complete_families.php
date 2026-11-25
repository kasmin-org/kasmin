<?php

use App\Support\Install\AutomationDefaults;
use App\Support\Install\MailTemplateDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Confirmed sign-ups (RG02), confirmed changes of addresses (F03), wording of consents (CO05), newsletter question (CO02),
 * visibility of own registrations in three steps (PE05a), notices about waitlist positions (GP11).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sign_ups', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->longText('data'); // encrypted
            $table->timestamp('expires_at');
            $table->timestamps();
        });
        Schema::create('email_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignId('contact_point_id')->nullable()->constrained()->cascadeOnDelete(); // null = additional address
            $table->string('token_hash', 64)->unique();
            $table->text('email'); // encrypted
            $table->timestamp('expires_at');
            $table->timestamps();
        });
        Schema::table('consents', function (Blueprint $table) {
            $table->text('text')->nullable(); // what was shown
        });
        Schema::table('public_forms', function (Blueprint $table) {
            $table->boolean('ask_newsletter')->default(false);
        });
        Schema::table('participations', function (Blueprint $table) {
            $table->unsignedInteger('notified_position')->nullable();
        });
        if (DB::table('system_meta')->where('key', 'own_registrations_visible')->where('value', '1')->exists()) {
            DB::table('system_meta')->updateOrInsert(['key' => 'own_registrations'], ['value' => 'visible', 'created_at' => now(), 'updated_at' => now()]);
        }
        if (DB::table('mail_templates')->where('is_standard', true)->exists()) {
            app(MailTemplateDefaults::class)->install();
            app(AutomationDefaults::class)->install();
        }
    }

    public function down(): void
    {
        Schema::table('participations', fn (Blueprint $table) => $table->dropColumn('notified_position'));
        Schema::table('public_forms', fn (Blueprint $table) => $table->dropColumn('ask_newsletter'));
        Schema::table('consents', fn (Blueprint $table) => $table->dropColumn('text'));
        Schema::dropIfExists('email_changes');
        Schema::dropIfExists('sign_ups');
    }
};
