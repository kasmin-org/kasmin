<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Kasmin</title></head>
<body style="font-family:system-ui,sans-serif;background:#f3faf3;color:#14501c;padding:2rem 1rem">
<main style="max-width:32rem;margin:0 auto;background:#fff;border-radius:1rem;padding:1.5rem">
<img src="/brand/kasmin-logo-horizontal.svg" alt="Kasmin" style="height:2.5rem">
<h1>{{ __('system.update.done', ['version' => $version]) }}</h1>
<p><a href="/admin/system">{{ __('system.update.back') }}</a></p>
</main></body></html>
