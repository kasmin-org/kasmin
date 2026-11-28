<?php

namespace App\Http\Controllers\Api;

use App\Models\Account;
use App\Models\Period;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * Base of the endpoints of the accounting (E1). Accounts, cost centres, funds and periods are referenced by their
 * number (id) or their exact name, accounts also by their account number. A refusal of the accounting
 * (AccountingException) answers 422 with its message, as the admin interface shows it (bootstrap/app.php).
 */
abstract class AccountingController extends ApiController
{
    /**
     * The record a reference names. Unknown: 422 (404 in the path); a reference that fits more than one record
     * (a name that is also the number of another one, a period name used twice): 422.
     *
     * @template T of Model
     *
     * @param  class-string<T>  $class
     * @return T
     */
    protected function find(string $class, mixed $reference, string $field, bool $inPath = false): Model
    {
        $text = is_scalar($reference) ? (string) $reference : '';
        $found = $text === '' ? collect() : $class::query()->where(function (Builder $q) use ($class, $reference, $text) {
            if (is_int($reference) || ctype_digit($text)) {
                $q->orWhere('id', (int) $text);
            }
            if (! is_int($reference)) {
                $q->orWhere('name', $text);
                if ($class === Account::class) {
                    $q->orWhere('number', $text);
                }
            }
        })->limit(2)->get();
        if ($found->count() > 1) {
            throw ValidationException::withMessages([$field => ["“{$text}” is ambiguous: use the id."]]);
        }
        $model = $found->first();
        if ($model === null) {
            if ($inPath) {
                throw (new ModelNotFoundException)->setModel($class);
            }
            throw ValidationException::withMessages([$field => ["Unknown {$field}: “{$text}”."]]);
        }

        return $model;
    }

    /**
     * The record of an optional reference; null for an empty one.
     *
     * @template T of Model
     *
     * @param  class-string<T>  $class
     * @return T|null
     */
    protected function findOptional(string $class, mixed $reference, string $field): ?Model
    {
        return $reference === null || $reference === '' ? null : $this->find($class, $reference, $field);
    }

    /** An amount in the currency of the installation (decimal) as cents. */
    protected function cents(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /** The period of today, else the latest one – what the report page starts with. */
    protected function currentPeriod(): ?Period
    {
        return Period::of(now()) ?? Period::query()->orderByDesc('ends_on')->first();
    }
}
