<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') – Kasmin</title>
    <link rel="icon" href="/favicon.ico" sizes="48x48">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-kasmin-50 text-kasmin-900 antialiased">
<main class="mx-auto w-full max-w-xl px-4 py-8 sm:py-12">
    <img src="/brand/kasmin-logo-horizontal.svg" alt="Kasmin" class="mb-6 h-10">
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-kasmin-100 sm:p-8">
        @yield('content')
    </div>
</main>
</body>
</html>
