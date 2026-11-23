@extends('layouts.plain')

@section('title', __('install.done_title'))

@section('content')
    <h1 class="mb-2 text-2xl font-semibold">{{ __('install.done_title') }}</h1>
    <p class="mb-4">{{ __('install.done_text', ['version' => $version]) }}</p>
    <a href="{{ url('/') }}" class="inline-block rounded-lg bg-kasmin-600 px-4 py-3 font-semibold text-white">{{ __('install.to_start') }}</a>
@endsection
