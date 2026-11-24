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
    @if ($invoices->isNotEmpty())
        <section class="mt-8" id="invoices">
            <h2 class="mb-2 text-lg font-semibold">{{ __('finance.invoices') }}</h2>
            <ul class="divide-y divide-kasmin-100 text-sm">
                @foreach ($invoices as $invoice)
                    @php($state = $invoice->isOverdue() ? 'overdue' : $invoice->status)
                    <li class="flex flex-wrap items-baseline justify-between gap-2 py-2">
                        <span>
                            <a class="underline" href="{{ route('portal.invoice.pdf', $invoice) }}" target="_blank" rel="noopener">{{ __('finance.type.'.$invoice->type) }} {{ $invoice->number }}</a>
                            @if ($invoice->event)<span class="text-kasmin-700">· {{ $invoice->event->title }}</span>@endif
                        </span>
                        <span class="text-right">
                            {{ \App\Models\Invoice::money(in_array($invoice->status, ['issued', 'partially_paid'], true) ? $invoice->openCents() : $invoice->total_cents, $invoice->currency) }}
                            <span @class(['ml-1 rounded-full px-2 py-0.5 text-xs font-semibold', 'bg-green-100 text-green-900' => $state === 'paid', 'bg-red-100 text-red-900' => $state === 'overdue', 'bg-amber-100 text-amber-900' => in_array($state, ['issued', 'partially_paid'], true), 'bg-gray-100 text-gray-700' => in_array($state, ['cancelled', 'corrected'], true)])>{{ __('finance.status.'.$state) }}</span>
                            @if (in_array($invoice->status, ['issued', 'partially_paid'], true) && $invoice->due_on)
                                <span class="block text-xs text-kasmin-700">{{ __('finance.due_on') }} {{ $invoice->due_on->isoFormat('LL') }} · {{ __('finance.reference') }} {{ $invoice->reference }}</span>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
    @if ($mails->isNotEmpty())
        <section class="mt-8">
            <h2 class="mb-2 text-lg font-semibold">{{ __('portal.mails') }}</h2>
            <ul class="divide-y divide-kasmin-100 text-sm">
                @foreach ($mails as $mail)
                    <li class="py-2"><a class="underline" href="{{ route('portal.mail', $mail->uuid) }}">{{ $mail->subject }}</a> <span class="text-kasmin-700">· {{ $mail->created_at->isoFormat('LLL') }}</span></li>
                @endforeach
            </ul>
        </section>
    @endif
@endsection
