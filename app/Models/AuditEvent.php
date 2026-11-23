<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only: entries can be created and read, never changed or deleted by the application.
 *
 * @property array<string, mixed>|null $changes
 * @property array<string, mixed>|null $meta
 */
class AuditEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'changes' => 'array', 'meta' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('The audit log is append-only.'));
        static::deleting(fn () => throw new LogicException('The audit log is append-only.'));
    }
}
