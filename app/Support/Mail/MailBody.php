<?php

namespace App\Support\Mail;

/**
 * The editable part of a finished mail inside the layout (MM06).
 */
class MailBody
{
    public const START = '<!--kasmin-body-->';

    public const END = '<!--/kasmin-body-->';

    public static function get(string $html): string
    {
        return preg_match('/'.preg_quote(self::START, '/').'(.*)'.preg_quote(self::END, '/').'/s', $html, $m) === 1 ? $m[1] : $html;
    }

    public static function set(string $html, string $body): string
    {
        $start = strpos($html, self::START);
        $end = strrpos($html, self::END);

        return $start === false || $end === false ? $body : substr($html, 0, $start + strlen(self::START)).$body.substr($html, $end);
    }
}
