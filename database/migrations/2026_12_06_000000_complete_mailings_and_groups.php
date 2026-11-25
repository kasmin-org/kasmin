<?php

use App\Support\Install\AfterMigrations;
use App\Support\Install\AutomationDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Static attachments (MT08), approval of large mailings (MM07), event groups (GR03).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_templates', function (Blueprint $table) {
            $table->json('attachments')->nullable();
        });
        Schema::table('mailings', function (Blueprint $table) {
            $table->json('attachments')->nullable();
            $table->json('document_ids')->nullable(); // documents of events
        });
        Schema::table('groups', function (Blueprint $table) {
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('updated_members_at')->nullable();
        });
        AfterMigrations::request(AutomationDefaults::class);
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('event_id');
            $table->dropColumn('updated_members_at');
        });
        Schema::table('mailings', fn (Blueprint $table) => $table->dropColumn(['attachments', 'document_ids']));
        Schema::table('mail_templates', fn (Blueprint $table) => $table->dropColumn('attachments'));
    }
};
