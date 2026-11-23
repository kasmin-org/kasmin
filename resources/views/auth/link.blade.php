@extends('layouts.plain')
@section('title', __('auth_link.title'))
@section('content')
    @if ($valid)
        <h1 class="mb-2 text-2xl font-semibold">{{ __('auth_link.link_title') }}</h1>
        <p class="mb-6 text-kasmin-700">{{ __('auth_link.link_intro') }}</p>
        <form method="post" action="{{ route('login.link.consume', $token) }}">
            @csrf
            <button class="w-full rounded-lg bg-kasmin-600 px-4 py-3 font-semibold text-white">{{ __('auth_link.continue') }}</button>
        </form>
    @else
        <h1 class="mb-2 text-2xl font-semibold">{{ __('auth_link.invalid_title') }}</h1>
        <p class="mb-6">{{ __('auth_link.invalid') }}</p>
        <a href="{{ route('login') }}" class="inline-block rounded-lg bg-kasmin-600 px-4 py-3 font-semibold text-white">{{ __('auth_link.new_link') }}</a>
    @endif
@endsection
