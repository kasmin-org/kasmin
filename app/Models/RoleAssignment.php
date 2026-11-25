<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Auth\Permissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int|null $event_id
 */
class RoleAssignment extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::saving(fn (RoleAssignment $assignment) => Permissions::guardAssignment($assignment, false));
        static::deleting(fn (RoleAssignment $assignment) => Permissions::guardAssignment($assignment, true));
        // Somebody who gets an administrative role needs a login.
        static::created(function (RoleAssignment $assignment): void {
            $person = $assignment->person;
            $email = $person?->primaryEmail();
            if ($person !== null && $email !== null && $assignment->role?->is_admin && $person->user === null && User::query()->where('email', Str::lower($email))->doesntExist()) {
                User::query()->create(['name' => $person->fullName(), 'email' => Str::lower($email), 'password' => Str::password(64), 'person_id' => $person->id]);
            }
        });
    }

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
