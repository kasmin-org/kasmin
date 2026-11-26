@extends('layouts.plain')

@section('title', $organisation)

@section('content')
    <h1 class="mb-2 text-2xl font-semibold">{{ $organisation }}</h1>
    <p class="mb-6 text-kasmin-700">{{ __('start.text') }}</p>
    <div class="grid gap-3 sm:grid-cols-2" data-start>
        <a href="{{ route('login') }}" class="block rounded-lg bg-kasmin-600 px-4 py-3 text-center font-semibold text-white hover:bg-kasmin-700">{{ __('start.login') }}</a>
        <a href="{{ route('register') }}" class="block rounded-lg bg-white px-4 py-3 text-center font-semibold ring-1 ring-kasmin-200">{{ __('start.register') }}</a>
    </div>
    @if ($events->isNotEmpty())
        <h2 class="mb-2 mt-8 text-lg font-semibold">{{ __('start.events') }}</h2>
        <ul class="text-sm">
            @foreach ($events as $event)
                <li class="border-b border-kasmin-100 py-2"><a class="underline" href="{{ route('public.event', $event->public_id) }}">{{ $event->title }}</a>
                    <span class="text-kasmin-700">· {{ $event->starts_at->isoFormat('LL') }} – {{ $event->ends_at->isoFormat('LL') }}</span></li>
            @endforeach
        </ul>
    @endif
@endsection
