@extends('layouts.plain')

@section('title', __('install.title'))

@section('content')
    <div class="mb-4 flex justify-end gap-3 text-sm">
        <a href="?lang=de" class="underline">Deutsch</a>
        <a href="?lang=en" class="underline">English</a>
    </div>
    <h1 class="mb-2 text-2xl font-semibold">{{ __('install.title') }}</h1>
    <p class="mb-6 text-kasmin-700">{{ __('install.intro') }}</p>

    <h2 class="mb-2 font-semibold">{{ __('install.requirements') }}</h2>
    <ul class="mb-6 space-y-1 text-sm">
        @foreach ($checks as $check)
            <li class="{{ $check['ok'] ? 'text-kasmin-700' : 'font-semibold text-red-700' }}">
                {{ $check['ok'] ? '✓' : '✗' }} {{ $check['label'] }}
            </li>
        @endforeach
    </ul>

    @if ($errors->any())
        <div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-800" role="alert">
            @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        </div>
    @endif

    <form method="post" action="{{ url('/install') }}" class="space-y-4">
        @csrf
        <label class="block">
            <span class="mb-1 block text-sm font-medium">{{ __('install.organisation') }}</span>
            <input name="organisation" required maxlength="200" value="{{ old('organisation') }}" class="w-full rounded-lg border border-kasmin-200 px-3 py-2">
        </label>
        <label class="block">
            <span class="mb-1 block text-sm font-medium">{{ __('install.locale') }}</span>
            <select name="locale" class="w-full rounded-lg border border-kasmin-200 px-3 py-2">
                <option value="de" @selected(app()->getLocale() === 'de')>Deutsch</option>
                <option value="en" @selected(app()->getLocale() === 'en')>English</option>
            </select>
        </label>
        <fieldset>
            <legend class="mb-1 text-sm font-medium">{{ __('install.defaults') }}</legend>
            <p class="mb-2 text-sm text-kasmin-700">{{ __('install.defaults_hint') }}</p>
            @foreach (['de' => __('install.defaults_de'), 'en' => __('install.defaults_en'), 'none' => __('install.defaults_none')] as $value => $label)
                <label class="flex items-center gap-2 py-1">
                    <input type="radio" name="defaults" value="{{ $value }}" @checked(old('defaults', app()->getLocale()) === $value) required>
                    <span>{{ $label }}</span>
                </label>
            @endforeach
        </fieldset>
        <label class="block">
            <span class="mb-1 block text-sm font-medium">{{ __('install.admin_name') }}</span>
            <input name="admin_name" required maxlength="200" value="{{ old('admin_name') }}" class="w-full rounded-lg border border-kasmin-200 px-3 py-2">
        </label>
        <label class="block">
            <span class="mb-1 block text-sm font-medium">{{ __('install.admin_email') }}</span>
            <input type="email" name="admin_email" required maxlength="254" value="{{ old('admin_email') }}" class="w-full rounded-lg border border-kasmin-200 px-3 py-2">
        </label>
        <button type="submit" @disabled(! $passes) class="w-full rounded-lg bg-kasmin-600 px-4 py-3 font-semibold text-white hover:bg-kasmin-700 disabled:opacity-50">
            {{ __('install.submit') }}
        </button>
    </form>
@endsection
