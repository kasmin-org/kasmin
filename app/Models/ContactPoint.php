<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Auth\Permissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * E-mail address or phone number of a person, with purposes (login, information, invoices, emergency).
 *
 * @property string $type
 * @property string $value
 * @property list<string>|null $purposes
 * @property Carbon|null $bounced_at
 * @property string|null $bounce_reason
 */
class ContactPoint extends Model
{
    use Auditable;

    public const PURPOSES = ['login', 'information', 'invoices', 'emergency'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['purposes' => 'array', 'is_primary' => 'boolean', 'verified_at' => 'datetime', 'bounced_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (ContactPoint $point) {
            if ($point->type === 'email') {
                $point->value = mb_strtolower(trim($point->value));
            }
            if ($point->isDirty('value')) {
                $point->forceFill(['bounced_at' => null, 'bounce_reason' => null]);
            }
            Permissions::guardContact($point);
        });
        static::deleting(fn (ContactPoint $point) => Permissions::guardContact($point, true));
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
