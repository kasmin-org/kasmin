<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A text that an administrator entered: a name, a label, a help text. An installation works in one language, so there
 * is one text – not one per language. It stays as it is when the language of the installation is changed later.
 *
 * Up to 0.27 these columns held one text per language ({"de": …, "en": …}). Such a value is still understood when
 * reading (old migrations and backups) and when writing (the standard forms, templates and automations of the software
 * come with both languages): the text of the language in use is taken.
 *
 * @implements CastsAttributes<string|null, string|array<string, mixed>|null>
 */
class OneText implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }
        $decoded = json_decode((string) $value, true);

        return match (true) {
            is_array($decoded) => self::pick($decoded),
            is_string($decoded) => $decoded,
            default => (string) $value, // a plain text written without the cast
        };
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        $text = is_array($value) ? self::pick($value) : ($value === null ? null : (string) $value);

        return $text === null ? null : (string) json_encode($text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * The one text out of texts per language: the language in use, otherwise English, otherwise whatever is there.
     *
     * @param  array<array-key, mixed>  $texts
     */
    public static function pick(array $texts, ?string $locale = null): ?string
    {
        $text = $texts[$locale ?? app()->getLocale()] ?? $texts['en'] ?? (reset($texts) ?: null);

        return is_scalar($text) ? (string) $text : null;
    }

    /**
     * Input that may still hold texts per language (clients and files of the versions up to 0.27): the named paths are
     * reduced to one text. "*" stands for every entry of a list.
     *
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $paths
     * @return array<array-key, mixed>
     */
    public static function collapse(array $data, array $paths): array
    {
        foreach ($paths as $path) {
            $data = (array) self::collapsePath($data, explode('.', $path));
        }

        return $data;
    }

    /** @param  list<string>  $segments */
    private static function collapsePath(mixed $data, array $segments): mixed
    {
        if ($segments === []) {
            return is_array($data) ? self::pick($data) : $data;
        }
        if (! is_array($data)) {
            return $data;
        }
        $segment = array_shift($segments);
        foreach ($segment === '*' ? array_keys($data) : (array_key_exists($segment, $data) ? [$segment] : []) as $key) {
            $data[$key] = self::collapsePath($data[$key], $segments);
        }

        return $data;
    }

    /** A text that may still come as texts per language (options of form fields, input of the API). */
    public static function of(mixed $value, ?string $locale = null): ?string
    {
        return match (true) {
            is_array($value) => self::pick($value, $locale),
            $value === null => null,
            default => (string) $value,
        };
    }
}
