@extends('layouts.plain')
@section('title', __('portal.profile'))
@section('content')
    <p class="mb-4 text-sm"><a class="underline" href="{{ route('portal') }}">← {{ __('portal.title') }}</a></p>
    <h1 class="mb-4 text-2xl font-semibold">{{ __('portal.profile') }}</h1>
    @if (session('status'))<div class="mb-4 rounded-lg bg-kasmin-100 p-3 text-sm" role="status">{{ session('status') }}</div>@endif
    @if ($errors->any())<div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-800" role="alert">{{ $errors->first() }}</div>@endif
    @php($input = 'w-full rounded-lg border border-kasmin-200 px-3 py-3')

    <h2 class="mb-2 text-lg font-semibold">{{ __('lists.email') }}</h2>
    @foreach ($points->where('type', 'email') as $point)
        <div class="mb-2 rounded-xl border border-kasmin-100 p-4 text-sm">
            <p class="font-medium">{{ $point->value }}</p>
            <form method="post" action="{{ route('portal.profile.email') }}" class="mt-2 space-y-2">@csrf
                <input type="hidden" name="replaces" value="{{ $point->id }}">
                <input type="email" name="email" required maxlength="254" placeholder="{{ __('portal.new_email') }}" aria-label="{{ __('portal.new_email') }}" class="{{ $input }}">
                <button class="rounded-lg bg-white px-4 py-2 font-semibold ring-1 ring-kasmin-200">{{ __('portal.change') }}</button>
            </form>
            <form method="post" action="{{ route('portal.profile.email.remove', $point->id) }}" class="mt-2">@csrf<button class="underline">{{ __('portal.remove') }}</button></form>
        </div>
    @endforeach
    <form method="post" action="{{ route('portal.profile.email') }}" class="mb-6 space-y-2 text-sm">@csrf
        <input type="email" name="email" required maxlength="254" placeholder="{{ __('portal.add_email') }}" aria-label="{{ __('portal.add_email') }}" class="{{ $input }}">
        <button class="rounded-lg bg-white px-4 py-2 font-semibold ring-1 ring-kasmin-200">{{ __('portal.add_email') }}</button>
        <p class="text-kasmin-700">{{ __('portal.email_hint') }}</p>
    </form>

    <h2 class="mb-2 text-lg font-semibold">{{ __('lists.phone') }}</h2>
    <form method="post" action="{{ route('portal.profile.phones') }}" class="mb-6 space-y-2">@csrf
        @foreach ([...$points->where('type', 'phone')->pluck('value')->all(), ''] as $phone)
            <input type="tel" name="phones[]" value="{{ $phone }}" maxlength="40" @if ($phone === '') placeholder="{{ __('portal.add_phone') }}" @endif aria-label="{{ __('lists.phone') }}" class="{{ $input }}">
        @endforeach
        <button class="w-full rounded-lg bg-kasmin-600 px-4 py-3 font-semibold text-white">{{ __('portal.save') }}</button>
    </form>

    @foreach ($households as $household)
        <h2 class="mb-2 text-lg font-semibold">{{ $household->name }}</h2>
        <form method="post" action="{{ route('portal.profile.family', $household->public_id) }}" class="mb-6 space-y-2">@csrf
            <label class="block"><span class="mb-1 block text-sm font-medium">{{ __('admin.household.street') }}</span><input name="street" value="{{ old('street', $household->street) }}" maxlength="200" autocomplete="street-address" class="{{ $input }}"></label>
            <div class="grid gap-2 sm:grid-cols-3">
                <label class="block"><span class="mb-1 block text-sm font-medium">{{ __('admin.household.postal_code') }}</span><input name="postal_code" value="{{ old('postal_code', $household->postal_code) }}" maxlength="16" autocomplete="postal-code" class="{{ $input }}"></label>
                <label class="block"><span class="mb-1 block text-sm font-medium">{{ __('admin.household.city') }}</span><input name="city" value="{{ old('city', $household->city) }}" maxlength="120" autocomplete="address-level2" class="{{ $input }}"></label>
                <label class="block"><span class="mb-1 block text-sm font-medium">{{ __('admin.household.country') }}</span><input name="country" value="{{ old('country', $household->country ?? 'AT') }}" maxlength="2" autocomplete="country" class="{{ $input }}"></label>
            </div>
            <label class="block"><span class="mb-1 block text-sm font-medium">{{ __('portal.invoice_recipient') }}</span>
                <select name="invoice_recipient_id" class="{{ $input }}">
                    @foreach ($household->members->where('pivot.role', 'guardian') as $g)<option value="{{ $g->id }}" @selected((int) $household->invoice_recipient_id === $g->id)>{{ $g->fullName() }}</option>@endforeach
                </select></label>
            <button class="w-full rounded-lg bg-kasmin-600 px-4 py-3 font-semibold text-white">{{ __('portal.save') }}</button>
        </form>
    @endforeach

    @if ($sessions !== [])
        <h2 class="mb-2 text-lg font-semibold">{{ __('portal.sessions') }}</h2>
        <p class="mb-2 text-sm">{{ __('portal.sessions_hint') }}</p>
        <ul class="mb-3 text-sm" data-sessions>
            @foreach ($sessions as $s)
                <li class="border-b border-kasmin-100 py-2">{{ $s['device'] }} · {{ $s['ip'] }} · {{ $s['last']->isoFormat('LLL') }}@if ($s['current']) · <strong>{{ __('portal.sessions_this') }}</strong>@endif</li>
            @endforeach
        </ul>
        @if (collect($sessions)->contains('current', false))
            <form method="post" action="{{ route('portal.profile.sessions') }}" class="mb-6">@csrf
                <button class="rounded-lg bg-white px-4 py-2 text-sm font-semibold ring-1 ring-kasmin-200">{{ __('portal.sessions_end') }}</button>
            </form>
        @else
            <div class="mb-6"></div>
        @endif
    @endif

    <h2 class="mb-2 text-lg font-semibold">{{ __('portal.mail_links') }}</h2>
    <p class="mb-2 text-sm">{{ __('portal.mail_links_hint') }}</p>
    <form method="post" action="{{ route('portal.profile.links') }}" class="mb-6" onsubmit="return confirm(this.dataset.confirm)" data-confirm="{{ __('portal.mail_links_confirm') }}">@csrf
        <button class="rounded-lg bg-white px-4 py-2 text-sm font-semibold ring-1 ring-kasmin-200">{{ __('portal.mail_links_end') }}</button>
    </form>

    <h2 class="mb-2 text-lg font-semibold">{{ __('portal.my_data') }}</h2>
    <p class="mb-2 text-sm">{{ __('portal.export_hint') }}</p>
    <a class="inline-block rounded-lg bg-white px-4 py-2 text-sm font-semibold ring-1 ring-kasmin-200" href="{{ route('portal.profile.export') }}">{{ __('portal.export') }}</a>
@endsection
