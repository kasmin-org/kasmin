<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Answers to the questions of an event; confidential values are encrypted. Not audited via the generic trait, so values
 * never reach the log.
 *
 * @property int $id
 * @property int $event_id
 * @property int $form_id
 * @property int|null $participation_id
 * @property int|null $household_id
 * @property int $form_version
 * @property array<string, mixed> $values
 * @property array<string, mixed>|null $confidential
 * @property array<string, string>|null $consents
 */
class RegistrationAnswer extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['values' => 'array', 'confidential' => 'encrypted:array', 'consents' => 'array'];
    }

    /** @return array<string, mixed> */
    public function allValues(): array
    {
        return $this->values + ($this->confidential ?? []);
    }
}
