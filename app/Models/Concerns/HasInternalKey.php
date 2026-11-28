<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * An internal key (column "key") that is never shown and never edited (B8). Records the software itself knows – the
 * role administrator, the standard templates, automations and forms – bring their own key. For every other record the
 * key is made once from the name when it is created: a slug, unique (with _2, _3 … if needed), and it does not change
 * when the name changes later. Answers to form fields, placeholders of mail templates, form files for other
 * installations and settings of older versions refer to it.
 *
 * A model can override internalKeySource() (what the key is made of), internalKeyScope() (where it has to be unique),
 * internalKeyLength() (length of the column) and internalKeyPrefix() (so own records never take a key a later version
 * might ship).
 */
trait HasInternalKey
{
    public static function bootHasInternalKey(): void
    {
        static::creating(function (Model $model): void {
            if (blank($model->getAttribute('key'))) {
                /** @var Model&self $model */
                $model->setAttribute('key', $model->newInternalKey());
            }
        });
    }

    protected function internalKeySource(): string
    {
        return (string) $this->getAttribute('name');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function internalKeyScope(Builder $query): Builder
    {
        return $query;
    }

    protected function internalKeyLength(): int
    {
        return 64;
    }

    protected function internalKeyPrefix(): string
    {
        return '';
    }

    public function newInternalKey(): string
    {
        $prefix = $this->internalKeyPrefix();
        $room = $this->internalKeyLength() - strlen($prefix) - 4; // room for "_999"
        $slug = trim(substr(Str::slug($this->internalKeySource(), '_'), 0, max(1, $room)), '_');
        $base = $prefix.($slug !== '' ? $slug : 'item');
        // "_" is a wildcard of LIKE: the query finds a few more, the check below is exact.
        $taken = $this->internalKeyScope(static::query())->where('key', 'like', $base.'%')->pluck('key')->map(fn ($k) => (string) $k)->all();
        $key = $base;
        for ($n = 2; in_array($key, $taken, true); $n++) {
            $key = $base.'_'.$n;
        }

        return $key;
    }
}
