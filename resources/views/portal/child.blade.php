@extends('layouts.plain')
@section('title', $child->fullName())
@section('content')
    <p class="mb-4 text-sm"><a class="underline" href="{{ route('portal') }}">← {{ __('portal.title') }}</a></p>
    @if (session('status'))<div class="mb-4 rounded-lg bg-kasmin-100 p-3 text-sm" role="status">{{ session('status') }}</div>@endif
    @if ($errors->any())<div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-800" role="alert">{{ $errors->first() }}</div>@endif
    <h1 class="text-2xl font-semibold">{{ $child->fullName() }}</h1>
    <p class="mb-6 text-sm text-kasmin-700">@if ($child->birth_date){{ $child->birth_date->isoFormat('LL') }} · {{ __('portal.age', ['age' => $child->ageAt()]) }}@endif</p>

    @if ($forms->isNotEmpty())
        <h2 class="mb-2 text-lg font-semibold">{{ __('forms.title') }}</h2>
        <div class="mb-6 grid gap-3">
            @foreach ($forms as $item)
                <a href="{{ route('portal.form.edit', [$child->public_id, $item['form']->key]) }}" class="flex items-center justify-between gap-2 rounded-xl border border-kasmin-100 p-4 hover:border-kasmin-500">
                    <span class="font-semibold">{{ $item['form']->label() }}</span>
                    <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $item['status'] === 'confirmed' ? 'bg-kasmin-100' : 'bg-summer-500/30' }}">{{ __('forms.status.'.$item['status']) }}</span>
                </a>
            @endforeach
        </div>
    @endif

    <h2 class="mb-2 text-lg font-semibold">{{ __('portal.registrations') }}</h2>
    <div class="mb-6 grid gap-3">
        @forelse ($participations as $p)
            <article class="rounded-xl border border-kasmin-100 p-4">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="font-semibold">{{ $p->event->title }}</h3>
                    <span class="rounded-full bg-kasmin-100 px-2 py-0.5 text-xs font-semibold">{{ __('events.status.'.$p->status) }}</span>
                </div>
                <p class="text-sm text-kasmin-700">{{ $p->event->starts_at->isoFormat('LL') }} – {{ $p->event->ends_at->isoFormat('LL') }}</p>
                @if ($p->status === 'offered')
                    <div class="mt-2 rounded-lg bg-summer-500/20 p-3 text-sm">
                        <p class="font-semibold">{{ __('portal.offer_title') }}</p>
                        <p>{{ __($p->offer_mode === 'payment' ? 'portal.offer_payment' : 'portal.offer_text', ['date' => $p->offer_expires_at?->isoFormat('LLL') ?? '–']) }}</p>
                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                            @if ($p->offer_mode !== 'payment')
                            <form method="post" action="{{ route('portal.child.offer', [$child->public_id, $p->id]) }}">
                                @csrf<input type="hidden" name="answer" value="accept">
                                <button class="w-full rounded-lg bg-kasmin-600 px-4 py-3 font-semibold text-white">{{ __('portal.offer_accept') }}</button>
                            </form>
                            @endif
                            <form method="post" action="{{ route('portal.child.offer', [$child->public_id, $p->id]) }}" onsubmit="return confirm('{{ __('portal.offer_decline_confirm') }}')">
                                @csrf<input type="hidden" name="answer" value="decline">
                                <button class="w-full rounded-lg bg-white px-4 py-3 font-semibold ring-1 ring-kasmin-200">{{ __('portal.offer_decline') }}</button>
                            </form>
                        </div>
                    </div>
                @endif
                @if ($p->status === 'waitlist')
                    @php($role = $p->event->roles()->where('role_key', $p->role_key)->first())
                    <p class="mt-2 font-medium">{{ __('events.waitlist_position', ['position' => $p->waitlistPosition(), 'total' => $role?->waitlistLength()]) }}</p>
                @endif
                @if (! in_array($p->status, ['cancelled', 'declined'], true) && isset($answerForms[$p->id]))
                    <p class="mt-2 text-sm"><a class="underline" href="{{ route('portal.child.answers', [$child->public_id, $p->id]) }}">{{ __('portal.answers') }}</a></p>
                @endif
                @if (in_array($p->status, ['registered', 'confirmed', 'attended', 'offered'], true))
                    @foreach ($p->event->documents as $d)<p class="mt-1 text-sm"><a class="underline" href="{{ route('event.document', $d) }}">{{ $d->title }}</a></p>@endforeach
                @endif
                @if ($self && ($guestsLeft[$p->id] ?? 0) > 0)
                    <form method="post" action="{{ route('portal.child.guest', [$child->public_id, $p->id]) }}" class="mt-3 text-sm">
                        @csrf
                        <label class="block"><span class="mb-1 block">{{ __('public.guests_left', ['count' => $guestsLeft[$p->id]]) }}</span>
                            <input type="email" name="email" required maxlength="254" placeholder="{{ __('auth_link.email') }}" class="w-full rounded-lg border border-kasmin-200 px-3 py-3"></label>
                        <button class="mt-2 rounded-lg bg-white px-4 py-2 font-semibold ring-1 ring-kasmin-200">{{ __('public.invite') }}</button>
                    </form>
                @endif
                @if (! in_array($p->status, ['cancelled', 'declined', 'attended', 'no_show', 'offered'], true))
                    <details class="mt-2 text-sm">
                    <summary class="cursor-pointer underline">{{ __('portal.cancel') }}</summary>
                    <div class="mt-2 rounded-lg bg-kasmin-50 p-3">
                        @foreach (\App\Support\Finance\CancellationTerms::for($p) as $term)<p class="mb-1">{{ $term }}</p>@endforeach
                    <form method="post" action="{{ route('portal.child.cancel', [$child->public_id, $p->id]) }}" class="mt-2" onsubmit="return confirm('{{ __('portal.cancel_confirm') }}')">
                        @csrf<button class="mt-2 rounded-lg bg-white px-4 py-2 font-semibold text-red-800 ring-1 ring-red-200">{{ __('portal.cancel_now') }}</button>
                    </form>
                    </div>
                    </details>
                @endif
            </article>
        @empty
            <p class="text-sm">{{ __('portal.no_registrations') }}</p>
        @endforelse
    </div>

    <h2 class="mb-2 text-lg font-semibold">{{ __('portal.open_events') }}</h2>
    <div class="grid gap-3">
        @forelse ($open as $item)
            @continue(in_array($item['event']->id, $registeredIds, true))
            <article class="rounded-xl border border-kasmin-100 p-4">
                <h3 class="font-semibold">{{ $item['event']->title }}</h3>
                <p class="text-sm text-kasmin-700">{{ $item['event']->starts_at->isoFormat('LL') }} – {{ $item['event']->ends_at->isoFormat('LL') }}@if ($item['event']->location) · {{ $item['event']->location }}@endif</p>
                @php($left = $item['role']->directLeft())
                <p class="mt-1 text-sm">{{ $left === null || $left > 0 ? __('events.places_left', ['count' => $left ?? '∞']) : __('events.waitlist_only') }}</p>
                <a class="mt-3 block w-full rounded-lg bg-kasmin-600 px-4 py-3 text-center font-semibold text-white sm:inline-block sm:w-auto" href="{{ route('portal.child.register.form', [$child->public_id, $item['event']->public_id, $item['role']->role_key]) }}">{{ $left === 0 ? __('portal.join_waitlist') : __('portal.register_child') }}</a>
            </article>
        @empty
            <p class="text-sm">{{ __('portal.no_open_events') }}</p>
        @endforelse
    </div>
    @if ($mayHaveLogin)
        <details class="mt-6 text-sm">
            <summary class="cursor-pointer underline">{{ __('portal.child_login') }}</summary>
            <form method="post" action="{{ route('portal.child.login', $child->public_id) }}" class="mt-2 space-y-2">
                @csrf
                <p>{{ __('portal.child_login_hint') }}</p>
                <input type="email" name="email" required maxlength="254" placeholder="{{ __('auth_link.email') }}" aria-label="{{ __('auth_link.email') }}" class="w-full rounded-lg border border-kasmin-200 px-3 py-3">
                <button class="rounded-lg bg-white px-4 py-2 font-semibold ring-1 ring-kasmin-200">{{ __('portal.create_login') }}</button>
            </form>
        </details>
    @endif
@endsection
