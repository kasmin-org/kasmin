@extends('layouts.plain')
@section('title', $event->title)
@section('content')
    <p class="mb-4 text-sm"><a class="underline" href="{{ route('portal') }}">← {{ __('portal.title') }}</a></p>
    <h1 class="mb-1 text-2xl font-semibold">{{ $event->title }}</h1>
    <p class="mb-4 text-sm text-kasmin-700">{{ __('portal.team_hint') }}</p>
    <nav class="mb-4 flex flex-wrap gap-2 text-sm" aria-label="{{ __('lists.title') }}">
        @foreach (\App\Http\Controllers\PortalTeamController::LISTS as $key)
            <a href="{{ route('portal.team', [$event->public_id, $key]) }}" @if ($key === $list) aria-current="page" @endif class="rounded-lg px-3 py-2 ring-1 ring-kasmin-200 {{ $key === $list ? 'bg-kasmin-600 text-white' : 'bg-white' }}">{{ __('lists.titles.'.$key) }}</a>
        @endforeach
    </nav>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead><tr>@foreach ($table->headers as $h)<th class="whitespace-nowrap border-b border-kasmin-200 px-2 py-2">{{ $h }}</th>@endforeach</tr></thead>
            <tbody>@forelse ($table->rows as $row)<tr>@foreach ($row as $cell)<td class="whitespace-pre-line border-b border-kasmin-100 px-2 py-2 align-top">{{ $cell }}</td>@endforeach</tr>@empty<tr><td class="px-2 py-2">{{ __('lists.empty') }}</td></tr>@endforelse</tbody>
        </table>
    </div>
@endsection
