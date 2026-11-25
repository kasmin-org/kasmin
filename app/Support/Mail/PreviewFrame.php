<?php

namespace App\Support\Mail;

use Illuminate\Support\HtmlString;

/**
 * Shows the HTML of a mail in a sandboxed frame: no scripts, no access to the session of the person looking at it.
 */
class PreviewFrame
{
    public static function make(string $html, string $title, int $height = 420): HtmlString
    {
        return new HtmlString('<iframe sandbox="" referrerpolicy="no-referrer" title="'.e($title).'" srcdoc="'.e($html).'" style="width:100%;height:'.$height.'px;border:1px solid #ddd;border-radius:8px;background:#fff"></iframe>');
    }
}
