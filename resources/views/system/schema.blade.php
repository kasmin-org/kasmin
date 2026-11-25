<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Kasmin – {{ __('system.schema.title.'.$status->value) }}</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; padding: 2rem 1rem; color: #14501C; background: #f6faf6; }
        main { max-width: 36rem; margin: 0 auto; background: #fff; border-radius: 1rem; padding: 1.5rem; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
        img { height: 2.5rem; }
        code { background: #eef5ee; padding: .1rem .3rem; border-radius: .25rem; word-break: break-all; }
    </style>
</head>
<body>
<main>
    <img src="/brand/kasmin-logo-horizontal.svg" alt="Kasmin">
    <h1>{{ __('system.schema.title.'.$status->value) }}</h1>
    <p>{{ __('system.schema.text.'.$status->value) }}</p>
    <p>{{ __('system.schema.versions', ['code' => $codeVersion, 'database' => $databaseVersion ?? '–']) }}</p>
</main>
</body>
</html>
