<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Sign-up of a legal guardian until the address is confirmed (RG02).
 *
 * @property int $id
 * @property array<string, mixed> $data
 * @property Carbon $expires_at
 */
class SignUp extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'encrypted:array', 'expires_at' => 'datetime'];
    }
}
