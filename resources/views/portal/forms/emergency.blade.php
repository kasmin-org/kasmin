@extends('layouts.plain')
@section('title', __('forms.emergency_contacts'))
@section('content')
    <p class="mb-4 text-sm"><a class="underline" href="{{ route('portal') }}">← {{ __('portal.title') }}</a></p>
    <h1 class="mb-2 text-2xl font-semibold">{{ __('forms.emergency_contacts') }}</h1>
    <p class="mb-6 text-kasmin-700">{{ __('forms.emergency_family_intro') }}</p>
    <form method="post" action="{{ route('portal.emergency.save', $household->public_id) }}" class="space-y-4">
        @csrf
        @php($contacts = $household->emergencyContacts->values())
        @for ($i = 0; $i < max($contacts->count() + 1, 2); $i++)
            <fieldset class="grid gap-2 rounded-xl border border-kasmin-100 p-4 sm:grid-cols-3">
                <legend class="text-sm font-medium">{{ __('forms.contact_n', ['n' => $i + 1]) }}</legend>
                <input name="contacts[{{ $i }}][name]" value="{{ $contacts[$i]->name ?? '' }}" placeholder="{{ __('forms.contact_name') }}" aria-label="{{ __('forms.contact_name') }}" class="rounded-lg border border-kasmin-200 px-3 py-2">
                <input name="contacts[{{ $i }}][relation]" value="{{ $contacts[$i]->relation ?? '' }}" placeholder="{{ __('forms.contact_relation') }}" aria-label="{{ __('forms.contact_relation') }}" class="rounded-lg border border-kasmin-200 px-3 py-2">
                <input name="contacts[{{ $i }}][phone]" value="{{ $contacts[$i]->phone ?? '' }}" type="tel" placeholder="{{ __('forms.contact_phone') }}" aria-label="{{ __('forms.contact_phone') }}" class="rounded-lg border border-kasmin-200 px-3 py-2">
            </fieldset>
        @endfor
        <button class="w-full rounded-lg bg-kasmin-600 px-4 py-3 font-semibold text-white">{{ __('portal.save') }}</button>
    </form>
@endsection
