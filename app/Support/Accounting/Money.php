<?php

namespace App\Support\Accounting;

use App\Models\Invoice;

/** Amounts in cents as text in the currency of the installation. */
final class Money
{
    public static function format(int $cents): string
    {
        return Invoice::money($cents);
    }
}
