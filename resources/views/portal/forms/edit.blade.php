@extends('layouts.plain')
@section('title', $form->label())
@section('content')
    <p class="mb-4 text-sm"><a class="underline" href="{{ route('portal.child', $child->public_id) }}">← {{ $child->fullName() }}</a></p>
    <h1 class="text-2xl font-semibold">{{ $form->label() }}</h1>
    <p class="mb-2 text-sm text-kasmin-700">{{ $child->fullName() }} · {{ __('forms.status.'.$status) }}@if ($current?->confirmed_at) · {{ __('forms.confirmed_on', ['date' => $current->confirmed_at->isoFormat('LL')]) }}@endif</p>
    @if ($form->descriptionText())<p class="mb-6">{{ $form->descriptionText() }}</p>@endif

    @if ($current && $status === 'expired')
        <form method="post" action="{{ route('portal.form.confirm', [$child->public_id, $form->key]) }}" class="mb-6 rounded-xl bg-summer-500/15 p-4">
            @csrf
            <p class="mb-3 font-medium">{{ __('forms.confirm_hint') }}</p>
            <button class="w-full rounded-lg bg-kasmin-600 px-4 py-3 font-semibold text-white">{{ __('forms.confirm_unchanged') }}</button>
        </form>
    @endif

    @if ($emergency !== [])
        <section class="mb-6 rounded-xl border border-kasmin-100 p-4">
            <h2 class="mb-2 font-semibold">{{ __('forms.emergency_contacts') }}</h2>
            <ol class="list-decimal space-y-1 pl-5 text-sm">
                @foreach ($emergency as $c)<li>{{ $c['name'] }} ({{ $c['relation'] }}) – {{ $c['phone'] ?: __('forms.no_phone') }}</li>@endforeach
            </ol>
            <p class="mt-2 text-sm text-kasmin-700">{{ __('forms.emergency_hint') }}</p>
        </section>
    @endif

    <form method="post" action="{{ route('portal.form.update', [$child->public_id, $form->key]) }}" class="space-y-4">
        @csrf
        @foreach ($form->fields as $field)
            @include('portal.forms.field', ['field' => $field, 'values' => $values])
        @endforeach
        <button class="w-full rounded-lg bg-kasmin-600 px-4 py-3 font-semibold text-white">{{ __('forms.save_confirm') }}</button>
    </form>

    @if ($history->isNotEmpty())
        <section class="mt-8">
            <h2 class="mb-2 font-semibold">{{ __('forms.history') }}</h2>
            <ul class="space-y-1 text-sm">
                @foreach ($history as $h)
                    <li>{{ $h->created_at->isoFormat('LLL') }} – {{ $h->changed_keys ? __('forms.changed', ['fields' => collect($h->changed_keys)->map(fn ($k) => $form->fields->firstWhere('key', $k)?->labelText() ?? $k)->implode(', ')]) : __('forms.unchanged') }}</li>
                @endforeach
            </ul>
        </section>
    @endif
@endsection
