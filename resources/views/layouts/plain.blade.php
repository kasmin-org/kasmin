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
@if (\App\Support\Auth\PortalPerson::previewing(request()))
    <div class="bg-summer-500 px-4 py-2 text-center text-sm font-semibold text-kasmin-900" role="status">
        {{ __('portal.preview_banner', ['name' => \App\Support\Auth\PortalPerson::for(request())?->fullName()]) }}
        <form method="post" action="{{ route('portal.view-as.end') }}" class="inline">@csrf<button class="ml-2 underline">{{ __('portal.preview_end') }}</button></form>
    </div>
@endif
@if (($upgradePending ?? false) && ! request()->routeIs('system.upgrade*'))
    <div class="bg-summer-500 px-4 py-3 text-center text-sm font-semibold text-kasmin-900" role="status" data-upgrade-banner>{{ __('system.upgrade.banner') }}</div>
@endif
<main class="mx-auto w-full max-w-xl px-4 py-8 sm:py-12">
    @unless (request()->boolean('embed'))<img src="/brand/kasmin-logo-horizontal.svg" alt="Kasmin" class="mb-6 h-10">@endunless
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-kasmin-100 sm:p-8">
        @error('preview')<div class="mb-4 rounded-lg bg-summer-500/20 p-3 text-sm" role="alert">{{ $message }}</div>@enderror
        @yield('content')
    </div>
    @unless (request()->boolean('embed') || request()->routeIs('mail.preferences*', 'approvals.*', 'install'))
        <form method="post" action="{{ route('language') }}" class="mt-4 flex justify-center gap-4 text-sm">
            @csrf
            @foreach (['de' => 'Deutsch', 'en' => 'English'] as $code => $label)
                <button name="locale" value="{{ $code }}" lang="{{ $code }}" @class(['px-2 py-1', 'font-semibold' => app()->getLocale() === $code, 'underline' => app()->getLocale() !== $code])>{{ $label }}</button>
            @endforeach
        </form>
    @endunless
</main>
</body>
</html>
