<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A sent public form. The data is encrypted and deleted once it is carried out, declined or expired.
 *
 * @property int $id
 * @property int $public_form_id
 * @property int|null $person_id
 * @property string $status
 * @property array<string, mixed>|null $data
 * @property string|null $note
 * @property Carbon $expires_at
 * @property Carbon|null $confirmed_at
 * @property Carbon $created_at
 */
class PublicSubmission extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'encrypted:array', 'expires_at' => 'datetime', 'confirmed_at' => 'datetime'];
    }

    /** @return BelongsTo<PublicForm, $this> */
    public function publicForm(): BelongsTo
    {
        return $this->belongsTo(PublicForm::class);
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
