<?php

namespace App\Support\Mail;

use Filament\Forms\Components\RichEditor\RichContentRenderer;

/**
 * HTML of mail templates from the editor (MT02): the editor escapes characters inside placeholders and conditions and
 * removes inline styles. Buttons get their style when the mail is made, so they follow the colour of the organisation.
 */
class TemplateHtml
{
    /** The editor takes a link with an inline style for bold text and drops the link; buttons get their style later. */
    public static function toEditor(string $html): string
    {
        return (string) preg_replace('/(<a\s[^>]*?)\sstyle="[^"]*"/i', '$1', $html);
    }

    /** @param  array<mixed>|string|null  $state  document of the editor or HTML */
    public static function fromEditor(array|string|null $state): string
    {
        $html = is_array($state) ? RichContentRenderer::make($state)->toUnsafeHtml() : (string) $state;
        $html = str_replace(['%7B%7B%20', '%20%7D%7D', '%7B%7B', '%7D%7D'], ['{{ ', ' }}', '{{', '}}'], $html);

        return (string) preg_replace_callback('/\{\{.*?\}\}|\{%.*?%\}/s', fn (array $m) => html_entity_decode(strip_tags($m[0]), ENT_QUOTES | ENT_HTML5), $html);
    }

    /** A link alone in its paragraph becomes a button. */
    public static function buttons(string $html, string $colour): string
    {
        return (string) preg_replace_callback('#<p(?:\s[^>]*)?>\s*<a\s([^>]*?)>((?:(?!</a>|</p>).)+)</a>\s*</p>#is', function (array $m) use ($colour): string {
            if (preg_match('/href="([^"]*)"/i', $m[1], $href) !== 1) {
                return $m[0];
            }

            return '<p style="margin:16px 0"><a href="'.$href[1].'" style="display:inline-block;background:'.$colour.';color:'.self::textOn($colour).';padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:600;">'.strip_tags($m[2]).'</a></p>';
        }, $html);
    }

    /** Black or white, whichever is easier to read on the colour (N05). */
    public static function textOn(string $colour): string
    {
        $luminance = 0.0;
        foreach ([[1, 0.2126], [3, 0.7152], [5, 0.0722]] as [$position, $weight]) {
            $c = hexdec(substr($colour, $position, 2)) / 255;
            $luminance += $weight * ($c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4);
        }

        return 1.05 / ($luminance + 0.05) >= ($luminance + 0.05) / 0.05 ? '#ffffff' : '#000000';
    }
}
