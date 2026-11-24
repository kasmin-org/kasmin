@extends('layouts.plain')
@section('title', $form->label())
@section('content')
    <p class="mb-4 text-sm"><a class="underline" href="{{ route('portal.child', $child->public_id) }}">← {{ $child->fullName() }}</a></p>
    <h1 class="text-2xl font-semibold">{{ $form->label() }}</h1>
    <p class="mb-4 text-sm text-kasmin-700">{{ __('portal.read_only') }}</p>
    <dl class="space-y-2 text-sm">
        @foreach ($form->fields as $field)
            <div><dt class="text-kasmin-700">{{ $field->labelText() }}</dt><dd>{{ \App\Support\Forms\FormValue::text($field, $values[$field->key] ?? null) ?? '–' }}</dd></div>
        @endforeach
    </dl>
@endsection
