@extends('layouts.plain')
@section('title', __('public.no_signup_title'))
@section('content')
    <h1 class="mb-2 text-2xl font-semibold">{{ __('public.no_signup_title') }}</h1>
    <p class="mb-6 text-kasmin-700">{{ __('public.no_signup') }}</p>
    <p class="text-sm"><a class="underline" href="{{ route('login') }}">{{ __('portal.have_account') }}</a></p>
@endsection
