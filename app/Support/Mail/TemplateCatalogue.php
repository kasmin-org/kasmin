<?php

namespace App\Support\Mail;

/**
 * Everything a mail template can use, for the "insert" dialogues of the editor: the placeholders (built from the same
 * example data the templates are checked against, so the list is always complete) and conditions put together from
 * a field, a comparison and a value – nobody has to write the syntax by hand.
 */
class TemplateCatalogue
{
    /** Comparisons a condition can make; those in NEEDS_VALUE compare with a value. */
    public const OPERATORS = ['filled', 'empty', 'equals', 'not_equals', 'greater', 'less'];

    public const NEEDS_VALUE = ['equals', 'not_equals', 'greater', 'less'];

    /** The names of all children, joined with commas and "and" – as the standard templates do it. */
    private const CHILDREN = '{% for child in children %}{{ child.first_name }}{% if loop.revindex == 2 %} %and% {% elseif not loop.last %}, {% endif %}{% endfor %}';

    /**
     * @param  string|null  $templateGroup  group of the template (MailTemplate::GROUPS): only its placeholders; null = all
     * @return array<string, array<string, string>> group label => [what is inserted => label with an example]
     */
    public static function placeholders(?string $templateGroup = null): array
    {
        $groups = [];
        foreach (array_filter(Placeholders::sample($templateGroup), fn ($value) => $value !== null) as $group => $value) {
            $label = self::label($group);
            if (! is_array($value)) {
                $groups[$label]['{{ '.$group.' }}'] = self::withExample($label, $value);
            } elseif (array_is_list($value)) {
                $groups[$label][str_replace('%and%', (string) __('mail.placeholders.and'), self::CHILDREN)] = (string) __('mail.placeholders.children_names');
                foreach ((array) ($value[0] ?? []) as $field => $example) {
                    $groups[$label]['{{ '.$group.'[0].'.$field.' }}'] = self::withExample(__('mail.placeholders.first_child').': '.self::label($group.'.'.$field), $example);
                }
            } else {
                foreach ($value as $field => $example) {
                    if (is_scalar($example) || $example === null) {
                        $groups[$label]['{{ '.$group.'.'.$field.' }}'] = self::withExample(self::label($group.'.'.$field), $example);
                    }
                }
            }
        }

        return $groups;
    }

    /**
     * Fields a condition can ask about.
     *
     * @return array<string, array<string, string>> group label => [expression => label]
     */
    public static function fields(?string $templateGroup = null): array
    {
        $groups = [];
        foreach (array_filter(Placeholders::sample($templateGroup), fn ($value) => $value !== null) as $group => $value) {
            $label = self::label($group);
            if (! is_array($value)) {
                continue;
            }
            if (array_is_list($value)) {
                $groups[$label][$group.'|length'] = (string) __('mail.placeholders.children_count');

                continue;
            }
            $groups[$label][$group] = (string) __('mail.placeholders.present', ['what' => $label]);
            foreach ($value as $field => $example) {
                if (is_scalar($example) || $example === null) {
                    $groups[$label][$group.'.'.$field] = self::label($group.'.'.$field);
                }
            }
        }

        return $groups;
    }

    /**
     * The condition as it is written into the template. Everything comes from lists or is quoted, so the result is
     * always valid.
     */
    public static function condition(string $field, string $operator, ?string $value, string $then, ?string $otherwise = null, ?string $templateGroup = null): string
    {
        if (! in_array($field, array_merge(...array_map('array_keys', array_values(self::fields($templateGroup)))), true) || ! in_array($operator, self::OPERATORS, true)) {
            throw new MailException('Unknown field or comparison.');
        }
        $value = trim((string) $value);
        $literal = is_numeric($value) ? $value : "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
        $test = match ($operator) {
            'filled' => $field,
            'empty' => 'not '.$field,
            'equals' => $field.' == '.$literal,
            'not_equals' => $field.' != '.$literal,
            'greater' => $field.' > '.(is_numeric($value) ? $value : '0'),
            'less' => $field.' < '.(is_numeric($value) ? $value : '0'),
        };

        return '{% if '.$test.' %}'.$then.(trim((string) $otherwise) !== '' ? '{% else %}'.$otherwise : '').'{% endif %}';
    }

    private static function label(string $path): string
    {
        $names = __('mail.placeholders.fields'); // the keys contain dots, so they are read from the list itself

        return is_array($names) && is_string($names[$path] ?? null) ? $names[$path] : $path;
    }

    private static function withExample(string $label, mixed $example): string
    {
        $example = is_bool($example) ? '' : trim((string) $example);

        return $example === '' ? $label : $label.' – '.__('mail.placeholders.example', ['value' => mb_strimwidth($example, 0, 40, '…')]);
    }
}
