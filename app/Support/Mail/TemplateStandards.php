<?php

namespace App\Support\Mail;

use App\Models\MailTemplate;
use App\Models\MailTemplateContent;
use App\Support\Install\MailTemplateDefaults;

/**
 * Compares the mail templates of the organisation with the shipped ones (MT01, TP01): a new standard is only offered.
 */
class TemplateStandards
{
    public function __construct(private readonly MailTemplateDefaults $defaults) {}

    /** A shipped text exists and differs from the text of the organisation. */
    public function differs(MailTemplate $template): bool
    {
        $standard = $this->defaults->contents($template->key);

        return $standard !== null && self::hash($this->current($template)) !== self::hash($standard);
    }

    /** The shipped text changed since the organisation looked at it last. */
    public function isNew(MailTemplate $template): bool
    {
        $standard = $this->defaults->contents($template->key);
        if ($standard === null || ! $template->is_standard || ! $this->differs($template)) {
            return false;
        }

        return $template->standard_hash !== null ? $template->standard_hash !== self::hash($standard) : $template->version === 1;
    }

    public function keep(MailTemplate $template): void
    {
        $standard = $this->defaults->contents($template->key);
        $template->forceFill(['standard_hash' => $standard === null ? null : self::hash($standard), 'standard_version' => MailTemplateDefaults::VERSION])->save();
    }

    public function adopt(MailTemplate $template): void
    {
        $standard = $this->defaults->contents($template->key);
        if ($standard === null) {
            return;
        }
        $template->keepFirstVersion();
        foreach ($standard as $locale => [$subject, $html]) {
            $template->contents()->updateOrCreate(['locale' => $locale], ['subject' => $subject, 'html' => $html, 'text' => null]);
        }
        $template->newVersion();
        $this->keep($template);
    }

    /**
     * Lines of both texts per language: "=" in both, "-" only in the organisation's, "+" only in the standard.
     *
     * @return array<string, list<array{0: string, 1: string}>>
     */
    public function diff(MailTemplate $template): array
    {
        $result = [];
        $current = $this->current($template);
        foreach ($this->defaults->contents($template->key) ?? [] as $locale => [$subject, $html]) {
            [$oldSubject, $oldHtml] = $current[$locale] ?? ['', ''];
            $result[$locale] = self::lines([$oldSubject, ...self::split($oldHtml)], [$subject, ...self::split($html)]);
        }

        return $result;
    }

    /** @return array<string, array{0: string, 1: string}> */
    private function current(MailTemplate $template): array
    {
        return $template->contents()->get()->mapWithKeys(fn (MailTemplateContent $c) => [$c->locale => [$c->subject, $c->html]])->sortKeys()->all();
    }

    /** @param  array<string, array{0: string, 1: string}>  $contents */
    private static function hash(array $contents): string
    {
        ksort($contents);

        // The style of links comes from the layout, so the editor removing it is no difference.
        return hash('sha256', (string) json_encode(array_map(fn (array $c) => [$c[0], TemplateHtml::toEditor($c[1])], $contents)));
    }

    /** @return list<string> */
    private static function split(string $html): array
    {
        return array_values(array_filter(explode("\n", trim((string) preg_replace('#(</p>|</li>|</h\d>|<br\s*/?>)#i', "$1\n", TemplateHtml::toEditor($html)))), fn (string $line) => trim($line) !== ''));
    }

    /**
     * @param  list<string>  $old
     * @param  list<string>  $new
     * @return list<array{0: string, 1: string}>
     */
    private static function lines(array $old, array $new): array
    {
        $table = array_fill(0, count($old) + 1, array_fill(0, count($new) + 1, 0));
        for ($i = count($old) - 1; $i >= 0; $i--) {
            for ($j = count($new) - 1; $j >= 0; $j--) {
                $table[$i][$j] = $old[$i] === $new[$j] ? $table[$i + 1][$j + 1] + 1 : max($table[$i + 1][$j], $table[$i][$j + 1]);
            }
        }
        $result = [];
        $i = $j = 0;
        while ($i < count($old) || $j < count($new)) {
            if ($i < count($old) && $j < count($new) && $old[$i] === $new[$j]) {
                $result[] = ['=', $old[$i]];
                $i++;
                $j++;
            } elseif ($j >= count($new) || ($i < count($old) && $table[$i + 1][$j] >= $table[$i][$j + 1])) {
                $result[] = ['-', $old[$i++]];
            } else {
                $result[] = ['+', $new[$j++]];
            }
        }

        return $result;
    }
}
