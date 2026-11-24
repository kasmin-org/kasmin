@extends('layouts.plain')
@section('title', __('auth_link.step_up_title'))
@section('content')
    <h1 class="mb-2 text-2xl font-semibold">{{ __('auth_link.step_up_title') }}</h1>
    <p class="mb-6 text-kasmin-700">{{ __('auth_link.step_up_intro') }}</p>
    <form method="post" action="{{ route('step-up.send') }}">
        @csrf
        <button class="w-full rounded-lg bg-kasmin-600 px-4 py-3 font-semibold text-white">{{ __('auth_link.step_up_button') }}</button>
    </form>
    <p class="mt-4 text-sm"><a class="underline" href="{{ route('portal') }}">{{ __('auth_link.step_up_back') }}</a></p>
@endsection
