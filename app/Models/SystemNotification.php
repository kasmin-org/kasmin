<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $key
 * @property string $level
 * @property array<string, string> $title
 * @property array<string, string> $message
 * @property string|null $url
 * @property Carbon|null $resolved_at
 */
class SystemNotification extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['title' => 'array', 'message' => 'array', 'resolved_at' => 'datetime'];
    }

    public function text(string $field, ?string $locale = null): string
    {
        $values = $this->getAttribute($field);
        $locale ??= app()->getLocale();

        return (string) ($values[$locale] ?? $values['en'] ?? reset($values));
    }
}
