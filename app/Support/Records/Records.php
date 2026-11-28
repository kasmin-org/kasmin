<?php

namespace App\Support\Records;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Records that administrators name – membership types, roles, forms, mail templates, automations, instalments of a
 * payment plan – are shown with their name and refer to each other by their database id (B8). Their names are unique
 * per type. In the REST API and MCP they are addressed by the id or by the exact name; the internal key of older
 * versions is still accepted, without being shown anywhere.
 *
 * A number is always taken as an id. A name matches without regard to upper and lower case and to spaces at the start
 * and end; a name that fits several records is refused with a clear message.
 */
final class Records
{
    /** The name as it is compared: trimmed, inner spaces collapsed, lower case. */
    public static function normalise(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    /**
     * The record with this id, exact name or (old) key, or null.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query  the records of the type, e.g. MembershipType::query() or $event->installments()->getQuery()
     * @param  string  $field  input field or route parameter, for the message about a name that is not unique
     * @param  string  $name  attribute that holds the name
     * @param  string|null  $key  column of the internal key, null when the type has none
     * @return TModel|null
     *
     * @throws ValidationException when the name fits several records
     */
    public static function find(Builder $query, mixed $ref, string $field, string $name = 'name', ?string $key = 'key'): ?Model
    {
        if (is_int($ref) || (is_string($ref) && ctype_digit($ref))) {
            return (clone $query)->whereKey((int) $ref)->first();
        }
        if (! is_string($ref) || trim($ref) === '') {
            return null;
        }

        return self::findByName($query, $ref, $field, $name) ?? ($key === null ? null : (clone $query)->where($key, $ref)->first());
    }

    /**
     * The record with exactly this name (also when the name is a number), or null.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return TModel|null
     *
     * @throws ValidationException when the name fits several records
     */
    public static function findByName(Builder $query, string $value, string $field, string $name = 'name'): ?Model
    {
        $matches = self::named(clone $query, $value, $name);
        if ($matches->count() > 1) {
            throw ValidationException::withMessages([$field => ["The name \"{$value}\" is not unique: several records have it. Use the id."]]);
        }

        return $matches->first();
    }

    /**
     * For a route parameter: 404 when nothing fits.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return TModel
     *
     * @throws ModelNotFoundException<TModel>
     */
    public static function findOrFail(Builder $query, mixed $ref, string $field, string $name = 'name', ?string $key = 'key'): Model
    {
        $model = self::find($query, $ref, $field, $name, $key);
        if ($model === null) {
            throw (new ModelNotFoundException)->setModel($query->getModel()::class, [is_scalar($ref) ? (string) $ref : '']);
        }

        return $model;
    }

    /**
     * For an input field: a validation error on the field when nothing fits.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return TModel
     *
     * @throws ValidationException
     */
    public static function require(Builder $query, mixed $ref, string $field, string $name = 'name', ?string $key = 'key'): Model
    {
        $model = self::find($query, $ref, $field, $name, $key);
        if ($model === null) {
            $given = is_scalar($ref) ? (string) $ref : '';
            throw ValidationException::withMessages([$field => ["Nothing with the id or name \"{$given}\" was found."]]);
        }

        return $model;
    }

    /**
     * Whether another record of the type has this name already.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    public static function nameTaken(Builder $query, string $value, mixed $ignore = null, string $name = 'name'): bool
    {
        return self::named(clone $query, $value, $name)->contains(fn (Model $m) => $ignore === null || $m->getKey() != $ignore);
    }

    /**
     * Records of the query with the name; read in PHP, as names are stored as JSON text.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Collection<int, TModel>
     */
    private static function named(Builder $query, string $value, string $name): Collection
    {
        $wanted = self::normalise($value);
        $matches = new Collection;
        foreach ($query->get() as $model) {
            if (self::normalise((string) $model->getAttribute($name)) === $wanted) {
                $matches->push($model);
            }
        }

        return $matches;
    }

    /**
     * The id of a record as it is passed back by the API: null stays null.
     */
    public static function id(?Model $model): ?int
    {
        return $model === null ? null : (int) $model->getKey();
    }
}
