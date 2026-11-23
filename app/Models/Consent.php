<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Consent or opt-out with proof (CO05).
 *
 * @property int $person_id
 * @property string $kind
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property bool $granted
 */
class Consent extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['granted' => 'boolean'];
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
