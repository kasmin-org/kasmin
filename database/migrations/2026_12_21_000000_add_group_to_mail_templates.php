<?php

use App\Models\MailTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Mail templates get a group that says what the mail is about (general, event, registration, invoice, membership).
 * The group decides which placeholders the template has. Standard templates get their fixed group; own templates the
 * one that fits what was their category.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('mail_templates', 'group')) {
            Schema::table('mail_templates', function (Blueprint $table) {
                $table->string('group', 32)->default('general')->index();
            });
        }
        foreach (DB::table('mail_templates')->get(['id', 'key', 'category', 'is_standard']) as $template) {
            $group = ($template->is_standard ? (MailTemplate::STANDARD_GROUPS[$template->key] ?? null) : null) ?? MailTemplate::CATEGORY_GROUPS[$template->category] ?? 'general';
            DB::table('mail_templates')->where('id', $template->id)->update(['group' => $group]);
        }
    }
};
