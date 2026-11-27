<?php

namespace App\Support\Accounting;

use RuntimeException;

/** A booking or a change of the accounting that is not allowed; the message is a text key of accounting.errors. */
class AccountingException extends RuntimeException
{
    /** @param  array<string, mixed>  $values */
    public static function because(string $key, array $values = []): self
    {
        return new self((string) __('accounting.errors.'.$key, $values));
    }
}
