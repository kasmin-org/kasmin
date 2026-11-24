<?php

use App\Support\Install\EventTemplateDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Event templates (ET01, ET02), more event data and documents (EE01), status by date (EE02, PF04), public page (EE07).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('is_template')->default(false)->index();
            $table->timestamp('announce_at')->nullable();
            $table->timestamp('registration_opens_at')->nullable();
            $table->timestamp('registration_closes_at')->nullable();
            $table->timestamp('status_checked_at')->nullable(); // dates before this were already applied
            $table->string('address')->nullable();
            $table->string('map_url', 500)->nullable();
            $table->string('return_point')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->string('image_path')->nullable();
            $table->boolean('public_page')->default(false);
        });
        Schema::create('event_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('path');
            $table->string('visibility', 16)->default('participants'); // participants, public
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
        if (DB::table('mail_templates')->where('is_standard', true)->exists()) {
            app(EventTemplateDefaults::class)->install();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('event_documents');
        Schema::table('events', fn (Blueprint $table) => $table->dropColumn([
            'is_template', 'announce_at', 'registration_opens_at', 'registration_closes_at', 'status_checked_at', 'address', 'map_url',
            'return_point', 'contact_name', 'contact_email', 'contact_phone', 'image_path', 'public_page',
        ]));
    }
};
