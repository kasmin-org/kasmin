<?php

namespace App\Support\Forms;

use App\Models\FormField;
use App\Models\Person;

/**
 * Readable text of an answer.
 */
class FormValue
{
    public static function text(FormField $field, mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            $value === FormField::NONE => (string) __('forms.none'),
            in_array($field->type, ['yes_no', 'consent'], true) => (string) __('forms.'.$value),
            $field->type === 'year_or_no' && in_array($value, ['no', 'unknown'], true) => (string) __('forms.'.$value),
            is_string($value) && str_starts_with($value, FormField::OTHER) => substr($value, strlen(FormField::OTHER)),
            $field->type === 'person' && is_array($value) => self::person($value),
            $field->type === 'select' => $field->choices()[(string) $value] ?? (string) $value,
            is_array($value) => collect($value)->map(fn ($v) => is_array($v) ? implode(' · ', array_filter($v)) : (self::choice($field, (string) $v)))->implode('; '),
            default => (string) $value,
        };
    }

    private static function choice(FormField $field, string $value): string
    {
        return str_starts_with($value, FormField::OTHER) ? substr($value, strlen(FormField::OTHER)) : ($field->choices()[$value] ?? $value);
    }

    /** @param  array<mixed>  $value */
    private static function person(array $value): ?string
    {
        // The name follows the person (SF10); the stored name is used once the person is deleted.
        $person = empty($value['person']) ? null : Person::query()->where('public_id', (string) $value['person'])->first();
        $name = $person?->fullName() ?? (string) ($value['name'] ?? '');

        return trim($name.(empty($value['number']) ? '' : ' ('.$value['number'].')')) ?: null;
    }
}
