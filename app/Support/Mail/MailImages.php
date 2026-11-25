<?php

namespace App\Support\Mail;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;

/**
 * Images in mails (MT02, MT06): stored with a random name, shown by a public address in previews and embedded in the
 * mail when it is sent, so mail programs show them without loading anything from the internet.
 */
class MailImages
{
    public const DIRECTORY = 'mail-images';

    public const TYPES = ['image/jpeg', 'image/png', 'image/gif'];

    public static function path(string $file): string
    {
        return self::DIRECTORY.'/'.$file;
    }

    public static function exists(string $file): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{8,64}\.(jpe?g|png|gif)$/', $file) === 1 && Storage::disk('local')->exists(self::path($file));
    }

    public static function store(UploadedFile $file): string
    {
        $extension = match ($file->getMimeType()) {
            'image/png' => 'png', 'image/gif' => 'gif', default => 'jpg',
        };
        $name = Str::random(32).'.'.$extension;
        Storage::disk('local')->putFileAs(self::DIRECTORY, $file, $name);

        return $name;
    }

    public static function url(string $file): string
    {
        return route('mail.image', basename($file));
    }

    /** Replaces the addresses of the images by embedded images. */
    public static function embed(Email $email, string $html): string
    {
        $done = [];

        return (string) preg_replace_callback('#src="https?://[^"\s]+/mail-images/([A-Za-z0-9_.-]+)"#', function (array $m) use ($email, &$done): string {
            if (! self::exists($m[1])) {
                return 'src=""';
            }
            if (! isset($done[$m[1]])) {
                $email->embed((string) Storage::disk('local')->get(self::path($m[1])), $m[1], (string) Storage::disk('local')->mimeType(self::path($m[1])));
                $done[$m[1]] = true;
            }

            return 'src="cid:'.$m[1].'"';
        }, $html);
    }
}
