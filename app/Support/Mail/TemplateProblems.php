<?php

namespace App\Support\Mail;

/**
 * Finds the usual slips in a template before Twig does and says in plain words what is wrong: a bracket that is not
 * closed, an "if" without "endif". Twig's own message stays the fallback for everything else.
 */
class TemplateProblems
{
    /** @return list<string> messages in the language of the interface; empty if nothing was found */
    public static function find(string $template): array
    {
        $problems = [];
        $outside = (string) preg_replace('/\{\{.*?\}\}|\{%.*?%\}|\{#.*?#\}/s', '', $template);
        foreach (['{{' => '}}', '{%' => '%}'] as $open => $close) {
            if (str_contains($outside, $open)) {
                $problems[] = (string) __('mail.problems.unclosed', ['open' => $open, 'close' => $close, 'near' => self::near($outside, $open)]);
            } elseif (str_contains($outside, $close)) {
                $problems[] = (string) __('mail.problems.unopened', ['open' => $open, 'close' => $close, 'near' => self::near($outside, $close)]);
            }
        }
        preg_match_all('/\{\{(.*?)\}\}|\{%(.*?)%\}/s', $template, $tags, PREG_SET_ORDER);
        $stack = [];
        foreach ($tags as $tag) {
            $written = ($tag[1] ?? '') !== '' ? $tag[1] : ($tag[2] ?? '');
            $inner = (string) preg_replace('/\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"/', '', $written); // brackets inside quoted texts do not count
            foreach (['(' => ')', '[' => ']'] as $open => $close) {
                if (substr_count($inner, $open) !== substr_count($inner, $close)) {
                    $problems[] = (string) __('mail.problems.bracket', ['open' => $open, 'close' => $close, 'near' => trim($tag[0])]);
                }
            }
            if (trim($written) === '') {
                $problems[] = (string) __('mail.problems.empty', ['near' => trim($tag[0])]);
            }
            if (($tag[2] ?? '') === '' || preg_match('/^\s*(\w+)/', $tag[2], $word) !== 1) {
                continue;
            }
            if (in_array($word[1], ['if', 'for'], true)) {
                $stack[] = [$word[1], trim($tag[0])];
            } elseif (in_array($word[1], ['endif', 'endfor'], true)) {
                $last = array_pop($stack);
                if ($last === null || 'end'.$last[0] !== $word[1]) {
                    $problems[] = (string) __('mail.problems.end_without_start', ['end' => $word[1], 'start' => substr($word[1], 3)]);
                    if ($last !== null) {
                        $stack[] = $last;
                    }
                }
            } elseif (in_array($word[1], ['else', 'elseif'], true) && ($stack === [] || ($word[1] === 'elseif' && end($stack)[0] !== 'if'))) {
                $problems[] = (string) __('mail.problems.end_without_start', ['end' => $word[1], 'start' => 'if']);
            }
        }
        foreach ($stack as [$kind, $near]) {
            $problems[] = (string) __('mail.problems.start_without_end', ['start' => $kind, 'end' => 'end'.$kind, 'near' => $near]);
        }

        return array_values(array_unique($problems));
    }

    /** Twig's messages for the two most common mistakes, in plain words. */
    public static function translate(string $message, ?string $group = null): string
    {
        // A placeholder that exists, but not in mails of this group (an invoice number in a mail to everybody).
        if ($group !== null && preg_match('/on a null variable(?: \("([^"]+)"\))?/', $message, $m) === 1) {
            $names = __('mail.placeholders.fields');
            $topic = (string) ($m[1] ?? '');

            return (string) __('mail.problems.not_in_group', ['what' => is_array($names) && isset($names[$topic]) ? $names[$topic] : $topic, 'group' => __('admin.mail.groups.'.$group)]);
        }
        if (preg_match('/Variable "([^"]+)" does not exist/', $message, $m) === 1) {
            return (string) __('mail.problems.unknown', ['name' => $m[1]]);
        }
        if (preg_match('/Key "([^"]+)" for (?:array|sequence\/mapping) with keys "([^"]*)" does not exist/', $message, $m) === 1) {
            return (string) __('mail.problems.unknown_field', ['name' => $m[1], 'known' => $m[2]]);
        }
        if (preg_match('/(?:Tag|Filter|Function) "([^"]+)" is not allowed|Unknown "([^"]+)" (?:tag|filter|function)/', $message, $m) === 1) {
            return (string) __('mail.problems.not_allowed', ['name' => $m[1] !== '' ? $m[1] : $m[2]]);
        }

        return $message;
    }

    private static function near(string $text, string $needle): string
    {
        $plain = trim((string) preg_replace('/\s+/', ' ', strip_tags($text)));
        $at = (int) mb_strpos($plain, $needle);

        return trim(mb_substr($plain, max(0, $at - 15), 45));
    }
}
