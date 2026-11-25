<?php

use App\Support\Mail\MailLinks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Stored mails contained links that decide about an automation. They are replaced by the placeholder; links in mails
 * that were already delivered keep working until they expire.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('outgoing_mails')->select(['id', 'html', 'text'])->where(fn ($q) => $q->where('html', 'like', '%/approvals/%')->orWhere('text', 'like', '%/approvals/%'))
            ->orderBy('id')->chunkById(200, function ($mails): void {
                foreach ($mails as $mail) {
                    $clean = fn (?string $content): string => (string) preg_replace('#(/approvals/)[A-Za-z0-9]{48}#', '${1}'.MailLinks::PLACEHOLDER, (string) $content);
                    DB::table('outgoing_mails')->where('id', $mail->id)->update(['html' => $clean($mail->html), 'text' => $clean($mail->text)]);
                }
            });
    }
};
