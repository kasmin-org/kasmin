<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * Login account of a person. Kasmin has no passwords in use: login works with one-time e-mail links/codes,
 * administrative roles additionally need a second factor (TOTP).
 *
 * @property int $id
 * @property string $email
 * @property int|null $person_id
 * @property string|null $two_factor_secret
 * @property Carbon|null $two_factor_confirmed_at
 */
#[Fillable(['name', 'email', 'password', 'person_id'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /** @return HasMany<LoginToken, $this> */
    public function loginTokens(): HasMany
    {
        return $this->hasMany(LoginToken::class);
    }

    public function isAdministrative(): bool
    {
        if ($this->person === null) {
            return false;
        }

        return Role::query()->whereIn('key', $this->person->activeRoleKeys())->where('is_admin', true)->exists();
    }

    public function requiresSecondFactor(): bool
    {
        if ($this->person === null) {
            return false;
        }

        return Role::query()->whereIn('key', $this->person->activeRoleKeys())->where('requires_second_factor', true)->exists();
    }

    /** @param  list<string>  $roles */
    public function hasAnyRole(array $roles): bool
    {
        return $this->person !== null && array_intersect($this->person->activeRoleKeys(), $roles) !== [];
    }

    /** Finance (invoices, payments, bank import): administrators, board and treasurers; auditors read only. */
    public function canManageFinance(): bool
    {
        return $this->hasAnyRole(['administrator', 'board', 'treasurer']);
    }

    public function canViewFinance(): bool
    {
        return $this->canManageFinance() || $this->hasAnyRole(['auditor']);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin' && $this->isAdministrative();
    }
}
