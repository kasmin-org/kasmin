@extends('layouts.plain')

@section('title', config('app.name'))

@section('content')
    <h1 class="mb-2 text-2xl font-semibold">{{ __('start.title') }}</h1>
    <p class="mb-4 text-kasmin-700">{{ __('start.text') }}</p>
    <p class="text-sm text-kasmin-700">{{ __('start.version', ['version' => config('kasmin.version')]) }}</p>
@endsection
