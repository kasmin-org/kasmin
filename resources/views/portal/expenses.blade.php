@extends('layouts.plain')
@section('title', __('accounting.expenses.portal.title'))
@section('content')
    <p class="mb-4 text-sm"><a class="underline" href="{{ route('portal') }}">← {{ __('portal.title') }}</a></p>
    <h1 class="mb-2 text-2xl font-semibold">{{ __('accounting.expenses.portal.title') }}</h1>
    <p class="mb-4 text-sm text-kasmin-700">{{ __('accounting.expenses.portal.intro') }}</p>
    @if (session('status'))<div class="mb-4 rounded-lg bg-kasmin-100 p-3 text-sm" role="status">{{ session('status') }}</div>@endif
    @if ($errors->any())<div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-800" role="alert">{{ $errors->first() }}</div>@endif
    @php($input = 'w-full rounded-lg border border-kasmin-200 bg-white px-3 py-3')
    @php($label = 'mb-1 block text-sm font-medium')

    <section class="mb-8" aria-labelledby="submit">
        <h2 id="submit" class="mb-3 text-lg font-semibold">{{ __('accounting.expenses.portal.submit') }}</h2>
        <form method="post" action="{{ route('portal.expenses.store') }}" enctype="multipart/form-data" class="space-y-3" data-expense-form>
            @csrf
            @if ($flatRates !== [])
                <label class="block"><span class="{{ $label }}">{{ __('accounting.expenses.portal.kind') }}</span>
                    <select name="kind" class="{{ $input }}" data-kind>
                        <option value="receipt">{{ __('accounting.expenses.portal.kind_receipt') }}</option>
                        @foreach ($flatRates as $rate)<option value="{{ $rate['name'] }}" @selected(old('kind') === $rate['name'])>{{ $rate['name'] }} ({{ \App\Support\Accounting\Money::format($rate['cents']) }})</option>@endforeach
                    </select></label>
            @endif
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="block"><span class="{{ $label }}">{{ __('accounting.expenses.portal.date') }}</span>
                    <input type="date" name="date" required max="{{ now()->toDateString() }}" value="{{ old('date', now()->toDateString()) }}" class="{{ $input }}"></label>
                <label class="block" data-receipt-only><span class="{{ $label }}">{{ __('accounting.expenses.portal.amount') }}</span>
                    <input type="number" name="amount" step="0.01" min="0.01" inputmode="decimal" value="{{ old('amount') }}" class="{{ $input }}" required></label>
                @if ($flatRates !== [])
                    <label class="block" data-flat-only hidden><span class="{{ $label }}">{{ __('accounting.expenses.portal.quantity') }}</span>
                        <input type="number" name="quantity" step="0.01" min="0.01" inputmode="decimal" value="{{ old('quantity', '1') }}" class="{{ $input }}"></label>
                @endif
            </div>
            <label class="block"><span class="{{ $label }}">{{ __('accounting.expenses.portal.purpose') }}</span>
                <input name="purpose" maxlength="250" value="{{ old('purpose') }}" class="{{ $input }}" required></label>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="block"><span class="{{ $label }}">{{ __('accounting.expenses.portal.event') }}</span>
                    <select name="event_id" class="{{ $input }}">
                        <option value="">{{ __('accounting.expenses.portal.none') }}</option>
                        @foreach ($events as $id => $title)<option value="{{ $id }}" @selected((int) old('event_id') === $id)>{{ $title }}</option>@endforeach
                    </select></label>
                @if ($accounts->count() > 1)
                    <label class="block"><span class="{{ $label }}">{{ __('accounting.expenses.portal.category') }}</span>
                        <select name="account_id" class="{{ $input }}">
                            @foreach ($accounts as $account)<option value="{{ $account->id }}" @selected((int) old('account_id', $defaultAccount) === $account->id)>{{ $account->name }}</option>@endforeach
                        </select></label>
                @endif
                @if ($costCenters->isNotEmpty())
                    <label class="block"><span class="{{ $label }}">{{ __('accounting.expenses.portal.cost_center') }}</span>
                        <select name="cost_center_id" class="{{ $input }}">
                            <option value="">{{ __('accounting.expenses.portal.none') }}</option>
                            @foreach ($costCenters as $id => $name)<option value="{{ $id }}" @selected((int) old('cost_center_id') === $id)>{{ $name }}</option>@endforeach
                        </select></label>
                @endif
                @if ($openAdvances->isNotEmpty())
                    <label class="block"><span class="{{ $label }}">{{ __('accounting.expenses.portal.advance') }}</span>
                        <select name="advance_id" class="{{ $input }}">
                            <option value="">{{ __('accounting.expenses.portal.none') }}</option>
                            @foreach ($openAdvances as $advance)<option value="{{ $advance->id }}" @selected((int) old('advance_id') === $advance->id)>{{ $advance->paid_on->isoFormat('L') }} · {{ \App\Support\Accounting\Money::format($advance->amount_cents) }}@if ($advance->purpose) · {{ $advance->purpose }}@endif</option>@endforeach
                        </select></label>
                @endif
            </div>
            <label class="block" data-receipt-only><span class="{{ $label }}">{{ __('accounting.expenses.portal.receipt') }}</span>
                {{-- No "capture": phones then offer the camera and the files. --}}
                <input type="file" name="receipt" accept="image/*,application/pdf" required class="block w-full rounded-lg border border-kasmin-200 bg-white px-3 py-3 text-sm">
                <span class="mt-1 block text-xs text-kasmin-700">{{ __('accounting.expenses.portal.receipt_hint') }}</span></label>
            <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="waived" value="1" @checked(old('waived')) class="mt-1 h-5 w-5"> <span>{{ __('accounting.expenses.portal.waive_label') }}</span></label>
            <button class="w-full rounded-lg bg-kasmin-600 px-4 py-3 font-semibold text-white">{{ __('accounting.expenses.portal.submit') }}</button>
        </form>
    </section>

    <section class="mb-8" aria-labelledby="claims">
        <h2 id="claims" class="mb-2 text-lg font-semibold">{{ __('accounting.expenses.portal.claims') }}</h2>
        @if ($claims->isEmpty())
            <p class="text-sm">{{ __('accounting.expenses.portal.none_yet') }}</p>
        @else
            <ul class="divide-y divide-kasmin-100 text-sm" data-claims>
                @foreach ($claims as $claim)
                    <li class="flex flex-wrap items-baseline justify-between gap-2 py-3">
                        <span class="min-w-0">
                            <span class="block font-medium">{{ $claim->purpose }}</span>
                            <span class="text-kasmin-700">{{ $claim->date->isoFormat('L') }}@if ($claim->event) · {{ $claim->event->title }}@endif</span>
                            @if ($claim->receipt_path)<a class="ml-1 underline" href="{{ route('portal.expenses.receipt', $claim->id) }}">{{ __('accounting.expenses.portal.show_receipt') }}</a>@endif
                            @if ($claim->status === 'rejected' && $claim->reason)<span class="block text-red-800">{{ __('accounting.expenses.mail.reason') }} {{ $claim->reason }}</span>@endif
                        </span>
                        <span class="text-right">
                            {{ $claim->amount() }}
                            <span @class(['ml-1 rounded-full px-2 py-0.5 text-xs font-semibold', 'bg-green-100 text-green-900' => in_array($claim->status, ['paid', 'donated', 'settled'], true), 'bg-red-100 text-red-900' => $claim->status === 'rejected', 'bg-amber-100 text-amber-900' => in_array($claim->status, ['submitted', 'approved'], true)])>{{ __('accounting.expenses.status.'.$claim->status) }}</span>
                            @if (in_array($claim->status, ['submitted', 'approved'], true) && ! $claim->waived && $claim->advance_id === null)
                                <form method="post" action="{{ route('portal.expenses.waive', $claim->id) }}" class="mt-1" onsubmit="return confirm(this.dataset.confirm)" data-confirm="{{ __('accounting.expenses.portal.waive_confirm') }}">@csrf
                                    <button class="text-xs underline">{{ __('accounting.expenses.portal.waive') }}</button></form>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    @if ($advances->isNotEmpty())
        <section class="mb-8" aria-labelledby="advances">
            <h2 id="advances" class="mb-2 text-lg font-semibold">{{ __('accounting.expenses.portal.advances') }}</h2>
            <ul class="divide-y divide-kasmin-100 text-sm">
                @foreach ($advances as $advance)
                    <li class="flex flex-wrap items-baseline justify-between gap-2 py-2">
                        <span>{{ $advance->paid_on->isoFormat('L') }}@if ($advance->purpose) · {{ $advance->purpose }}@endif</span>
                        <span class="text-right">{{ \App\Support\Accounting\Money::format($advance->amount_cents) }}
                            <span class="block text-xs text-kasmin-700">{{ $advance->isSettled() ? __('accounting.expenses.portal.settled', ['date' => $advance->settled_on?->isoFormat('L')]) : __('accounting.expenses.portal.open', ['amount' => \App\Support\Accounting\Money::format($advance->openCents())]) }}</span></span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="mb-6" aria-labelledby="iban">
        <h2 id="iban" class="mb-2 text-lg font-semibold">{{ __('accounting.expenses.portal.iban') }}</h2>
        <p class="mb-2 text-sm" data-iban>{{ $iban !== null ? __('accounting.expenses.portal.iban_current', ['iban' => $iban]) : __('accounting.expenses.portal.iban_none') }}</p>
        <form method="post" action="{{ route('portal.expenses.iban') }}" class="space-y-2">@csrf
            <input name="iban" maxlength="42" autocomplete="off" spellcheck="false" placeholder="AT.." aria-label="IBAN" class="{{ $input }} font-mono uppercase">
            <p class="text-xs text-kasmin-700">{{ __('accounting.expenses.portal.iban_hint') }}</p>
            <button class="rounded-lg bg-white px-4 py-2 font-semibold ring-1 ring-kasmin-200">{{ __('accounting.expenses.portal.iban_save') }}</button>
        </form>
    </section>

    @if ($flatRates !== [])
        <script>
            // A flat rate needs no amount and no receipt, but a quantity; the purpose becomes a note.
            (() => {
                const form = document.querySelector('[data-expense-form]');
                const kind = form?.querySelector('[data-kind]');
                if (!form || !kind) return;
                const update = () => {
                    const flat = kind.value !== 'receipt';
                    form.querySelectorAll('[data-receipt-only]').forEach((el) => { el.hidden = flat; el.querySelectorAll('input').forEach((i) => { i.required = !flat; i.disabled = flat; }); });
                    form.querySelectorAll('[data-flat-only]').forEach((el) => { el.hidden = !flat; });
                    form.querySelector('[name=purpose]').required = !flat;
                };
                kind.addEventListener('change', update);
                update();
            })();
        </script>
    @endif
@endsection
