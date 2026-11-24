<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Person;
use App\Support\Api\ApiContext;

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

    protected function perPage(): int
    {
        return max(1, min(200, (int) request()->query('per_page', '50')));
    }
}
