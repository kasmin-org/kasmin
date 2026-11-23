@extends('layouts.plain')
@section('title', __('portal.title'))
@section('content')
    <div class="mb-4 flex items-center justify-between gap-2">
        <h1 class="text-2xl font-semibold">{{ __('portal.hello', ['name' => $person?->fullName() ?? '']) }}</h1>
        <form method="post" action="{{ route('logout') }}">@csrf<button class="text-sm underline">{{ __('portal.logout') }}</button></form>
    </div>
    @forelse ($households as $household)
        <section class="mb-6" aria-labelledby="h{{ $household->id }}">
            <h2 id="h{{ $household->id }}" class="mb-3 text-lg font-semibold">{{ $household->name }}</h2>
            <div class="grid gap-3">
                @foreach ($household->members->where('pivot.role', 'child') as $child)
                    <article class="rounded-xl border border-kasmin-100 p-4">
                        <h3 class="font-semibold">{{ $child->fullName() }}</h3>
                        @if ($child->birth_date)
                            <p class="text-sm text-kasmin-700">{{ __('portal.age', ['age' => $child->ageAt()]) }}</p>
                        @endif
                    </article>
                @endforeach
            </div>
            <h3 class="mb-2 mt-4 text-sm font-semibold uppercase tracking-wide text-kasmin-700">{{ __('portal.guardians') }}</h3>
            <ul class="text-sm">
                @foreach ($household->members->where('pivot.role', 'guardian') as $guardian)
                    <li>{{ $guardian->fullName() }}</li>
                @endforeach
            </ul>
        </section>
    @empty
        <p>{{ __('portal.no_family') }}</p>
    @endforelse
@endsection
