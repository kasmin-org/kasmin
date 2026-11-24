@extends('layouts.plain')
@section('title', $event->title)
@section('content')
    @if ($event->image_path)<img src="{{ route('public.event.image', $event->public_id) }}" alt="" class="mb-4 w-full rounded-xl">@endif
    <h1 class="text-2xl font-semibold">{{ $event->title }}</h1>
    <p class="text-kasmin-700">{{ $event->starts_at->isoFormat('LLL') }} – {{ $event->ends_at->isoFormat('LLL') }}</p>
    @if ($event->location)<p>{{ $event->location }}@if ($event->address), {{ $event->address }}@endif
        @if ($event->map_url) · <a class="underline" href="{{ $event->map_url }}" target="_blank" rel="noopener">{{ __('admin.event.map') }}</a>@endif</p>@endif
    @if ($event->description)<p class="mt-4 whitespace-pre-line">{{ $event->description }}</p>@endif
    <ul class="mt-4 space-y-1">
        @foreach ($event->roles->where('registered_by', '!=', 'admin') as $role)
            @php($left = $role->directLeft())
            <li><strong>{{ __('events.roles.'.$role->role_key) }}</strong>@if ($role->min_age !== null || $role->max_age !== null) ({{ $role->min_age ?? 0 }}–{{ $role->max_age ?? '99' }})@endif@if ($role->price_cents > 0): {{ \App\Models\Invoice::money($role->price_cents, $event->currency) }}@endif
                @if ($event->status === 'open') · {{ $left === null || $left > 0 ? __('events.places_left', ['count' => $left ?? '∞']) : __('events.waitlist_only') }}@endif</li>
        @endforeach
    </ul>
    @if ($documents->isNotEmpty())
        <ul class="mt-4 space-y-1">@foreach ($documents as $d)<li><a class="underline" href="{{ route('event.document', $d) }}">{{ $d->title }}</a></li>@endforeach</ul>
    @endif
    <div class="mt-6 grid gap-3">
        @if ($event->status === 'open')
            @if ($event->roles->contains('registered_by', 'guardian'))<a class="block rounded-lg bg-kasmin-600 px-4 py-3 text-center font-semibold text-white" href="{{ route('register') }}" target="_top">{{ __('public.register_child') }}</a>@endif
            @foreach ($forms as $f)<a class="block rounded-lg bg-kasmin-600 px-4 py-3 text-center font-semibold text-white" href="{{ route('public.form', [$f->slug] + (request()->boolean('embed') ? ['embed' => 1] : [])) }}">{{ $f->titleText() }}</a>@endforeach
        @else
            <p role="status">{{ __($event->status === 'announced' ? 'public.not_open' : 'public.closed_event') }}@if ($event->status === 'announced' && $event->registration_opens_at) {{ $event->registration_opens_at->isoFormat('LLL') }}@endif</p>
        @endif
    </div>
    @if ($event->contact_name || $event->contact_email)<p class="mt-6 text-sm">{{ __('admin.event.contact') }}: {{ $event->contact_name }}@if ($event->contact_email) · <a class="underline" href="mailto:{{ $event->contact_email }}">{{ $event->contact_email }}</a>@endif@if ($event->contact_phone) · {{ $event->contact_phone }}@endif</p>@endif
@endsection
