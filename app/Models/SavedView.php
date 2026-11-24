<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Filters, sorting and columns of a table under a name (AD11).
 *
 * @property int $id
 * @property int $user_id
 * @property string $page
 * @property string $name
 * @property bool $shared
 * @property array<string, mixed> $state
 */
class SavedView extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['shared' => 'boolean', 'state' => 'array'];
    }

    /** @return Builder<SavedView> */
    public static function visibleTo(User $user, string $page): Builder
    {
        return self::query()->where('page', $page)->where(fn (Builder $q) => $q->where('user_id', $user->id)->orWhere('shared', true))->orderBy('name');
    }
}
