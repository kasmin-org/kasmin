@extends('layouts.plain')
@section('title', $event->title)
@section('content')
    <p class="mb-4 text-sm"><a class="underline" href="{{ route('portal.child', $child->public_id) }}">← {{ $child->fullName() }}</a></p>
    <h1 class="text-2xl font-semibold">{{ $event->title }}</h1>
    <p class="text-sm text-kasmin-700">{{ $child->fullName() }} · {{ $event->starts_at->isoFormat('LL') }} – {{ $event->ends_at->isoFormat('LL') }}@if ($event->location) · {{ $event->location }}@endif</p>

    @if ($errors->any())<div class="my-4 rounded-lg bg-red-50 p-3 text-sm text-red-800" role="alert">{{ $errors->first() }}</div>@endif

    @if ($participation === null)
        <section class="my-4 rounded-xl bg-kasmin-50 p-4 text-sm">
            <p class="font-medium">{{ $left === null || $left > 0 ? __('events.places_left', ['count' => $left ?? '∞']) : __('events.waitlist_only') }}</p>
            @if ($price > 0)<p class="mt-1">{{ __('portal.price', ['amount' => \App\Models\Invoice::money($price, $event->currency)]) }}</p>
                @foreach ($installments as $i)<p>{{ $i['label'] }}: {{ \App\Models\Invoice::money($i['amount'], $event->currency) }}</p>@endforeach
                <p class="mt-1 text-kasmin-700">{{ __('portal.price_hint') }}</p>@endif
            @if ($terms !== [])<h2 class="mt-3 font-semibold">{{ __('portal.terms') }}</h2>@foreach ($terms as $term)<p>{{ $term }}</p>@endforeach @endif
        </section>
    @endif

    <form method="post" action="{{ $participation === null ? route('portal.child.register', $child->public_id) : route('portal.child.answers.save', [$child->public_id, $participation->id]) }}" class="space-y-4">
        @csrf
        <input type="hidden" name="event" value="{{ $event->public_id }}">
        <input type="hidden" name="role" value="{{ $role->role_key }}">
        @foreach ($fields as $field)
            @include('portal.forms.field', ['field' => $field, 'values' => $values])
        @endforeach
        @if ($editable)
            <button class="w-full rounded-lg bg-kasmin-600 px-4 py-3 font-semibold text-white">{{ $participation !== null ? __('portal.save_answers') : ($left === 0 ? __('portal.join_waitlist') : __('portal.register_child')) }}</button>
        @else
            <p class="text-sm">{{ __('portal.answers_closed') }}</p>
        @endif
    </form>
@endsection
