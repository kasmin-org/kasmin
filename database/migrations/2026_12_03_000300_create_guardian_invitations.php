<?php

use App\Support\Install\AfterMigrations;
use App\Support\Install\MailTemplateDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invitations of further legal guardians (RG07), notices about the end of a guardianship (PE05), and pairs of people that
 * are not duplicates (PE10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guardian_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by_id')->nullable()->constrained('people')->nullOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->longText('data')->nullable(); // encrypted: names and address; removed when used or expired
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
        Schema::create('duplicate_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('first_id')->constrained('people')->cascadeOnDelete();
            $table->foreignId('second_id')->constrained('people')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['first_id', 'second_id']);
        });
        // Children added to a family in the admin interface had no guardianship, so their guardians could not open them.
        $children = DB::table('household_members')->where('role', 'child')->whereNotIn('person_id', DB::table('guardianships')->select('child_id'))->get();
        foreach ($children as $child) {
            foreach (DB::table('household_members')->where('household_id', $child->household_id)->where('role', 'guardian')->pluck('person_id') as $guardian) {
                DB::table('guardianships')->insertOrIgnore(['guardian_id' => $guardian, 'child_id' => $child->person_id, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        if (DB::table('mail_templates')->where('is_standard', true)->exists()) {
            AfterMigrations::request(MailTemplateDefaults::class);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('duplicate_dismissals');
        Schema::dropIfExists('guardian_invitations');
    }
};
