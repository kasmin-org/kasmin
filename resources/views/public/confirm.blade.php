@extends('layouts.plain')
@section('title', $form?->titleText() ?? __('public.invalid_title'))
@section('content')
    @if ($form === null)
        <h1 class="mb-2 text-2xl font-semibold">{{ __('public.invalid_title') }}</h1>
        <p>{{ __('public.invalid') }}</p>
    @else
        <h1 class="mb-4 text-2xl font-semibold">{{ $form->titleText() }}</h1>
        <form method="post" action="{{ route('public.confirm.store', $token) }}">@csrf
            <button class="w-full rounded-lg bg-kasmin-600 px-4 py-3 font-semibold text-white">{{ __('public.confirm') }}</button>
        </form>
    @endif
@endsection
