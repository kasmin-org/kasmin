<?php

use App\Support\Install\MailTemplateDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('mail_templates')->where('is_standard', true)->exists()) {
            app(MailTemplateDefaults::class)->install();
        }
    }
};
