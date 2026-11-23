<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One version of a person's answers. Every save or confirmation creates a new row (history, SF17, SF22).
 * The values are encrypted at rest (SF23). Not audited via the generic trait, so values never reach the log.
 *
 * @property int $id
 * @property int $form_id
 * @property int $person_id
 * @property array<string, mixed> $values
 * @property list<string>|null $changed_keys
 * @property Carbon|null $confirmed_at
 * @property Carbon $created_at
 */
class FormResponse extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['values' => 'encrypted:array', 'changed_keys' => 'array', 'confirmed_at' => 'datetime'];
    }

    /** @return BelongsTo<Form, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /** @return BelongsTo<Person, $this> */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'confirmed_by_id');
    }
}
