<?php

namespace App\Support\Forms;

use App\Models\FormField;

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
            $field->type === 'select' => $field->choices()[(string) $value] ?? (string) $value,
            is_array($value) => collect($value)->map(fn ($v) => is_array($v) ? implode(' · ', array_filter($v)) : ($field->choices()[(string) $v] ?? $v))->implode('; '),
            default => (string) $value,
        };
    }
}
