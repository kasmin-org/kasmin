<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A way to divide the people of an event: groups, rooms or a duty (EC04).
 *
 * @property int $id
 * @property int $event_id
 * @property string $name
 * @property string $kind
 * @property array<string, mixed>|null $options
 * @property int $sort
 */
class EventDivision extends Model
{
    public const KINDS = ['groups', 'rooms', 'duty'];

    /** What the proposal looks at, by kind. */
    public const DEFAULTS = [
        'groups' => ['balance_age' => true, 'balance_gender' => true, 'separate_gender' => false, 'siblings' => 'apart', 'roles' => ['child', 'junior_counsellor']],
        'rooms' => ['balance_age' => false, 'balance_gender' => false, 'separate_gender' => true, 'siblings' => 'together', 'roles' => ['child']],
        'duty' => ['balance_age' => true, 'balance_gender' => true, 'separate_gender' => false, 'siblings' => 'any', 'roles' => ['child']],
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['options' => 'array'];
    }

    public function option(string $key): mixed
    {
        return $this->options[$key] ?? self::DEFAULTS[$this->kind][$key] ?? null;
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return HasMany<EventUnit, $this> */
    public function units(): HasMany
    {
        return $this->hasMany(EventUnit::class)->orderBy('sort')->orderBy('id');
    }
}
