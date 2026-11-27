<?php

namespace App\Support\System;

/**
 * Reads the changelog (CHANGELOG.md, "Keep a Changelog" form) and shows the part between two versions as simple,
 * safe HTML – for the update page and the mail after an automatic update.
 */
class Changelog
{
    /**
     * Everything newer than $from up to and including $to, newest first; each version under its own heading.
     */
    public static function between(string $markdown, string $from, string $to): string
    {
        $from = self::plain($from);
        $html = '';
        foreach (self::sections($markdown) as $version => $body) {
            if (version_compare($version, $from, '>') && version_compare($version, self::plain($to), '<=')) {
                $html .= '<h3 data-changelog-version="'.e($version).'">'.e($version).'</h3>'.self::html($body, 4);
            }
        }

        return $html;
    }

    /** The section of one version, without its heading. */
    public static function of(string $markdown, string $version): string
    {
        $body = self::sections($markdown)[self::plain($version)] ?? null;

        return $body === null ? '' : self::html($body, 3);
    }

    /** @return array<string, string> version => text of its section, in the order of the file (newest first) */
    public static function sections(string $markdown): array
    {
        $sections = [];
        if (preg_match_all('/^## \[(\d+\.\d+\.\d+)\][^\n]*\n(.*?)(?=^## \[|^\[[^\]]+\]: |\z)/ms', $markdown, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $m) {
                $sections[$m[1]] = trim($m[2]);
            }
        }

        return $sections;
    }

    private static function html(string $body, int $heading): string
    {
        $html = '';
        $list = false;
        foreach (explode("\n", $body) as $line) {
            $line = trim($line);
            $item = str_starts_with($line, '- ');
            if ($list && ! $item) {
                $html .= '</ul>';
                $list = false;
            }
            if ($item) {
                $html .= ($list ? '' : '<ul>').'<li>'.self::inline(substr($line, 2)).'</li>';
                $list = true;
            } elseif (str_starts_with($line, '### ')) {
                $html .= "<h{$heading}>".e(substr($line, 4))."</h{$heading}>";
            } elseif ($line !== '') {
                $html .= '<p>'.self::inline($line).'</p>';
            }
        }

        return $html.($list ? '</ul>' : '');
    }

    /** Bold, code and emphasis; everything else is escaped. Links stay as text. */
    private static function inline(string $text): string
    {
        $text = e((string) preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $text));
        $text = (string) preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
        $text = (string) preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);

        return (string) preg_replace('/(?<![\w*])\*([^*\s][^*]*)\*(?![\w*])/', '<em>$1</em>', $text);
    }

    private static function plain(string $version): string
    {
        return (string) preg_replace('/[-+].*$/', '', ltrim($version, 'v'));
    }
}
