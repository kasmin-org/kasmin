@extends('layouts.plain')
@section('title', __('auth_link.tf_title'))
@section('content')
    <h1 class="mb-2 text-2xl font-semibold">{{ __('auth_link.tf_title') }}</h1>
    @if ($setup)
        <p class="mb-4 text-kasmin-700">{{ __('auth_link.tf_setup') }}</p>
        <img src="{{ $qr }}" alt="QR code" class="mx-auto mb-2 h-48 w-48">
        <p class="mb-4 break-all text-center font-mono text-sm">{{ $secret }}</p>
    @else
        <p class="mb-4 text-kasmin-700">{{ __('auth_link.tf_challenge') }}</p>
    @endif
    @if ($errors->any())<div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-800" role="alert">{{ $errors->first() }}</div>@endif
    <form method="post" action="{{ route('two-factor.verify') }}" class="space-y-4">
        @csrf
        <input name="code" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" aria-label="{{ __('auth_link.code') }}" class="w-full rounded-lg border border-kasmin-200 px-3 py-3 text-center text-2xl tracking-widest">
        <button class="w-full rounded-lg bg-kasmin-600 px-4 py-3 font-semibold text-white">{{ __('auth_link.continue') }}</button>
    </form>
@endsection
