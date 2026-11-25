<?php

namespace App\Http\Controllers;

use App\Support\Mail\MailImages;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logo and images of mails for previews (MT02, MT06). Sent mails contain the images themselves.
 */
class MailImageController extends Controller
{
    public function show(string $file): Response
    {
        abort_unless(MailImages::exists($file), 404);

        return Storage::disk('local')->response(MailImages::path($file), null, ['Cache-Control' => 'public, max-age=86400']);
    }
}
