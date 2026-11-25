<?php

use App\Support\Mail\MailLinks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Stored mails contained personal login links. They are replaced by the placeholder (mails that still have to be sent
 * get a new link when they are sent), and the links already stored can no longer be used from the mail log.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('outgoing_mails')->select(['id', 'html', 'text'])->where(fn ($q) => $q->where('html', 'like', '%/login/link/%')->orWhere('text', 'like', '%/login/link/%'))
            ->orderBy('id')->chunkById(200, function ($mails): void {
                foreach ($mails as $mail) {
                    $clean = fn (?string $content): string => (string) preg_replace('#(/login/link/)[A-Za-z0-9]{48}#', '${1}'.MailLinks::PLACEHOLDER, (string) $content);
                    DB::table('outgoing_mails')->where('id', $mail->id)->update(['html' => $clean($mail->html), 'text' => $clean($mail->text)]);
                }
            });
    }
};
