<?php

namespace App\Support\Records;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Names are unique per type (B8): no other record of the query may have the name, regardless of upper and lower case.
 * Used by the forms of the admin interface and by the API.
 *
 * @template TModel of Model
 */
class UniqueName implements ValidationRule
{
    /**
     * @param  Builder<TModel>  $query  the records of the type
     * @param  mixed  $ignore  id of the record that is being changed
     * @param  string  $name  attribute that holds the name
     */
    public function __construct(private readonly Builder $query, private readonly mixed $ignore = null, private readonly string $name = 'name') {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && trim($value) !== '' && Records::nameTaken($this->query, $value, $this->ignore, $this->name)) {
            $fail((string) __('admin.form.name_taken'));
        }
    }
}
