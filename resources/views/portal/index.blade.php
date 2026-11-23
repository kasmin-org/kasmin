@extends('layouts.plain')
@section('title', __('portal.title'))
@section('content')
    <div class="mb-4 flex items-center justify-between gap-2">
        <h1 class="text-2xl font-semibold">{{ __('portal.hello', ['name' => $person?->fullName() ?? '']) }}</h1>
        <form method="post" action="{{ route('logout') }}">@csrf<button class="text-sm underline">{{ __('portal.logout') }}</button></form>
    </div>
    @if (session('status'))<div class="mb-4 rounded-lg bg-kasmin-100 p-3 text-sm" role="status">{{ session('status') }}</div>@endif
    @forelse ($households as $household)
        <section class="mb-6" aria-labelledby="h{{ $household->id }}">
            <h2 id="h{{ $household->id }}" class="mb-3 text-lg font-semibold">{{ $household->name }}</h2>
            <div class="grid gap-3">
                @foreach ($household->members->where('pivot.role', 'child') as $child)
                    <a href="{{ route('portal.child', $child->public_id) }}" class="block rounded-xl border border-kasmin-100 p-4 hover:border-kasmin-500">
                        <h3 class="font-semibold">{{ $child->fullName() }}</h3>
                        @if ($child->birth_date)
                            <p class="text-sm text-kasmin-700">{{ __('portal.age', ['age' => $child->ageAt()]) }}</p>
                        @endif
                        @foreach ($child->participations()->with('event')->whereNotIn('status', ['cancelled', 'declined'])->get() as $p)
                            <p class="mt-1 text-sm">{{ $p->event->title }}: <strong>{{ __('events.status.'.$p->status) }}</strong>@if ($p->status === 'waitlist') ({{ __('events.waitlist_position', ['position' => $p->waitlistPosition(), 'total' => $p->event->roles()->where('role_key', $p->role_key)->first()?->waitlistLength()]) }})@endif</p>
                        @endforeach
                    </a>
                @endforeach
            </div>
            <h3 class="mb-2 mt-4 text-sm font-semibold uppercase tracking-wide text-kasmin-700">{{ __('portal.guardians') }}</h3>
            <ul class="text-sm">
                @foreach ($household->members->where('pivot.role', 'guardian') as $guardian)
                    <li>{{ $guardian->fullName() }}</li>
                @endforeach
            </ul>
            @if ($household->members->where('pivot.role', 'guardian')->contains('id', $person?->id))
                <a href="{{ route('portal.emergency', $household->public_id) }}" class="mt-2 inline-block text-sm underline">{{ __('forms.emergency_contacts') }}</a>
            @endif
        </section>
    @empty
        <p>{{ __('portal.no_family') }}</p>
    @endforelse
    <a href="{{ route('portal.child.create') }}" class="mt-2 block w-full rounded-lg bg-kasmin-600 px-4 py-3 text-center font-semibold text-white">{{ __('portal.add_child') }}</a>
@endsection
