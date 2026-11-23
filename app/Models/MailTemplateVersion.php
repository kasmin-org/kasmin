<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $version
 * @property array<string, mixed> $snapshot
 */
class MailTemplateVersion extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'created_at' => 'datetime'];
    }
}
