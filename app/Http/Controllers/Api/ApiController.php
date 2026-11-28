<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\CostCenter;
use App\Models\Event;
use App\Models\Person;
use App\Support\Api\ApiContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Base of the REST API v1 (X01–X05). Every request acts for the token's user, limited to the token's scopes.
 */
abstract class ApiController extends Controller
{
    protected function scope(string $scope): void
    {
        ApiContext::require($scope);
    }

    protected function person(string $id): Person
    {
        return Person::query()->where('public_id', $id)->whereNull('anonymised_at')->firstOrFail();
    }

    protected function event(string $id): Event
    {
        return Event::query()->where('public_id', $id)->firstOrFail();
    }

    /**
     * A cost centre given by its id or its exact name; null or "" empties the field.
     *
     * @throws ValidationException for an unknown one
     */
    protected function costCenterRef(mixed $value, string $field): ?int
    {
        $id = $this->reference(CostCenter::query()->select('id'), $value);
        if ($id === false) {
            throw ValidationException::withMessages([$field => 'Unknown cost centre: give its id or its exact name.']);
        }

        return $id;
    }

    /**
     * An income account given by its id or its exact name; null or "" empties the field.
     *
     * @throws ValidationException for an unknown one
     */
    protected function revenueAccountRef(mixed $value, string $field): ?int
    {
        $accounts = Account::query()->select('id')->where('type', 'income')->where(fn ($q) => $q->whereNull('system_key')->orWhere('system_key', '!=', 'discounts'));
        $id = $this->reference($accounts, $value);
        if ($id === false) {
            throw ValidationException::withMessages([$field => 'Unknown income account: give the id or the exact name of an income account.']);
        }

        return $id;
    }

    /**
     * @param  Builder<Account>|Builder<CostCenter>  $query
     * @return int|false|null false: nothing with this id or name
     */
    private function reference(Builder $query, mixed $value): int|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_int($value) && ! is_string($value)) {
            return false;
        }
        $byId = is_int($value) || ctype_digit($value) ? (clone $query)->whereKey((int) $value)->value('id') : null;
        $id = $byId ?? (is_string($value) ? (clone $query)->where('name', trim($value))->value('id') : null);

        return $id !== null ? (int) $id : false;
    }

    protected function perPage(): int
    {
        return max(1, min(200, (int) request()->query('per_page', '50')));
    }
}
