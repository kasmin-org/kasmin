@extends('layouts.plain')
@section('title', __('auth_link.code_title'))
@section('content')
    @if (session('status'))<div class="mb-4 rounded-lg bg-kasmin-100 p-3 text-sm" role="status">{{ session('status') }}</div>@endif
    <h1 class="mb-2 text-2xl font-semibold">{{ __('auth_link.code_title') }}</h1>
    <p class="mb-6 text-kasmin-700">{{ __('auth_link.code_hint') }}</p>
    @if ($errors->any())<div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-800" role="alert">{{ $errors->first() }}</div>@endif
    <form method="post" action="{{ route('login.code.consume') }}" class="space-y-4">
        @csrf
        <label class="block">
            <span class="mb-1 block text-sm font-medium">{{ __('auth_link.email') }}</span>
            <input type="email" name="email" required value="{{ old('email', $email) }}" class="w-full rounded-lg border border-kasmin-200 px-3 py-3 text-base">
        </label>
        <label class="block">
            <span class="mb-1 block text-sm font-medium">{{ __('auth_link.code') }}</span>
            <input name="code" required inputmode="text" autocapitalize="characters" autocorrect="off" spellcheck="false" maxlength="20" placeholder="ABC-123" autocomplete="one-time-code" value="{{ old('code') }}" class="w-full rounded-lg border border-kasmin-200 px-3 py-3 text-center text-2xl tracking-widest">
        </label>
        <button class="w-full rounded-lg bg-kasmin-600 px-4 py-3 font-semibold text-white">{{ __('auth_link.continue') }}</button>
    </form>
    <p class="mt-4 text-sm"><a class="underline" href="{{ route('login') }}">{{ __('auth_link.new_link') }}</a></p>
@endsection
