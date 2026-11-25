<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * New address of a person until it is confirmed (F03).
 *
 * @property int $id
 * @property int $person_id
 * @property int|null $contact_point_id
 * @property string $email
 * @property Carbon $expires_at
 */
class EmailChange extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['email' => 'encrypted', 'expires_at' => 'datetime'];
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
