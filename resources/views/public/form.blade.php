@extends('layouts.plain')
@section('title', $title)
@section('content')
    <h1 class="mb-2 text-2xl font-semibold">{{ $title }}</h1>
    @if ($intro)<p class="mb-4 whitespace-pre-line text-kasmin-700">{{ $intro }}</p>@endif
    @if ($sent)
        <div class="rounded-lg bg-kasmin-100 p-4" role="status">{{ __('public.sent') }}</div>
    @elseif ($state !== 'open')
        <div class="rounded-lg bg-summer-500/20 p-4" role="status">{{ $state === 'early' ? __('public.early', ['date' => $form?->opens_at?->isoFormat('LLL')]) : __('public.closed') }}</div>
    @else
        @if ($places)<p class="mb-4 font-medium">{{ $places }}</p>@endif
        @if ($errors->any())<div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-800" role="alert">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
        <form method="post" action="{{ $action }}" class="space-y-4">
            @csrf
            <div class="hidden" aria-hidden="true"><input name="website" tabindex="-1" autocomplete="off"></div>
            <input type="hidden" name="locale" value="{{ app()->getLocale() }}">
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block"><span class="mb-1 block text-sm font-medium">{{ __('admin.person.first_name') }}</span>
                    <input name="first_name" required maxlength="120" value="{{ old('first_name') }}" autocomplete="given-name" class="w-full rounded-lg border border-kasmin-200 px-3 py-3"></label>
                <label class="block"><span class="mb-1 block text-sm font-medium">{{ __('admin.person.last_name') }}</span>
                    <input name="last_name" required maxlength="120" value="{{ old('last_name') }}" autocomplete="family-name" class="w-full rounded-lg border border-kasmin-200 px-3 py-3"></label>
            </div>
            @if ($askEmail)
                <label class="block"><span class="mb-1 block text-sm font-medium">{{ __('auth_link.email') }}</span>
                    <input type="email" name="email" required maxlength="254" value="{{ old('email') }}" autocomplete="email" class="w-full rounded-lg border border-kasmin-200 px-3 py-3"></label>
            @endif
            @if ($askBirthDate)
                <label class="block"><span class="mb-1 block text-sm font-medium">{{ __('admin.person.birth_date') }}</span>
                    <input type="date" name="birth_date" required value="{{ old('birth_date') }}" autocomplete="bday" class="w-full rounded-lg border border-kasmin-200 px-3 py-3"></label>
                <label class="block"><span class="mb-1 block text-sm font-medium">{{ __('lists.phone') }}</span>
                    <input type="tel" name="phone" maxlength="40" value="{{ old('phone') }}" autocomplete="tel" class="w-full rounded-lg border border-kasmin-200 px-3 py-3"></label>
            @endif
            @foreach ($fields as $field)
                @include('portal.forms.field', ['field' => $field, 'values' => old('values', [])])
            @endforeach
            <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="privacy" value="1" required class="mt-1"> <span>{{ __('portal.privacy_consent') }}</span></label>
            <button class="w-full rounded-lg bg-kasmin-600 px-4 py-3 font-semibold text-white">{{ __('public.submit') }}</button>
        </form>
    @endif
@endsection
