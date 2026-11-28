<?php

namespace App\Console\Commands;

use App\Support\Accounting\DocumentBookings;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Books invoices, credit notes, payments and refunds that have no booking yet (E1): after the accounting has started
 * with its first period, or after a booking failed. Safe to run again: documents are booked once.
 */
class BookDocumentsCommand extends Command
{
    protected $signature = 'kasmin:book-documents {--from= : only documents from this day on (default: the start of the first period)}';

    protected $description = 'Books the invoices and payments that have no booking yet';

    public function handle(DocumentBookings $bookings): int
    {
        $from = $this->option('from');
        try {
            $day = is_string($from) && $from !== '' ? Carbon::parse($from) : null;
        } catch (\Throwable) {
            $this->error('The date after --from is not valid.');

            return self::FAILURE;
        }
        $made = $bookings->backfill($day);
        $this->info($made.' bookings made.');
        foreach ($bookings->failures as $failure) {
            $this->warn($failure);
        }

        return $bookings->failures === [] ? self::SUCCESS : self::FAILURE;
    }
}
