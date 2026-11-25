<?php

namespace App\Support\Mail;

use App\Models\IncomingMail;
use App\Models\OutgoingMail;
use App\Support\Audit\Audit;
use App\Support\Settings;

/**
 * Retention of the mail log (ML05): by default as long as the person exists; mails with invoices at least as long as the
 * invoices have to be kept. Mails that still have to be sent are never deleted.
 */
class MailRetention
{
    public function run(): int
    {
        $years = Settings::int('retention_mail_years');
        if ($years < 1) {
            return 0;
        }
        $invoices = max($years, Settings::int('retention_invoice_years'));
        $finished = ['sent', 'failed', 'bounced', 'cancelled'];
        $deleted = OutgoingMail::query()->whereIn('status', $finished)->where('created_at', '<', now()->subYears($years))
            ->where(fn ($q) => $q->whereNull('attachments')->orWhere('attachments', 'not like', '%invoice_id%')->orWhere('created_at', '<', now()->subYears($invoices)))->delete();
        $deleted += IncomingMail::query()->where('received_at', '<', now()->subYears($years))->delete();
        if ($deleted > 0) {
            Audit::record('mail.retention_deleted', null, null, ['mails' => $deleted, 'years' => $years], 'system');
        }

        return $deleted;
    }
}
