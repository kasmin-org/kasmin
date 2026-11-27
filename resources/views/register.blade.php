@extends('layouts.plain')
@section('title', $title)
@section('content')
    <h1 class="mb-2 text-2xl font-semibold">{{ $title }}</h1>
    <p class="mb-6 whitespace-pre-line text-kasmin-700">{{ $intro }}</p>
    @if ($state !== 'open')
    <div class="rounded-lg bg-summer-500/20 p-4" role="status">{{ $state === 'early' ? __('public.early', ['date' => $form->opens_at?->isoFormat('LLL')]) : __('public.closed') }}</div>
    @else
    @if ($errors->any())<div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-800" role="alert">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <form method="post" action="{{ $action }}" class="space-y-4">
        @csrf
        <div class="hidden" aria-hidden="true"><input name="website" tabindex="-1" autocomplete="off"></div>
        <div class="grid gap-4 sm:grid-cols-2">
            <label class="block"><span class="mb-1 block text-sm font-medium">{{ __('admin.person.first_name') }}</span>
                <input name="first_name" required maxlength="120" value="{{ old('first_name') }}" autocomplete="given-name" class="w-full rounded-lg border border-kasmin-200 px-3 py-3"></label>
            <label class="block"><span class="mb-1 block text-sm font-medium">{{ __('admin.person.last_name') }}</span>
                <input name="last_name" required maxlength="120" value="{{ old('last_name') }}" autocomplete="family-name" class="w-full rounded-lg border border-kasmin-200 px-3 py-3"></label>
        </div>
        <label class="block"><span class="mb-1 block text-sm font-medium">{{ __('auth_link.email') }}</span>
            <input type="email" name="email" required maxlength="254" value="{{ old('email') }}" autocomplete="email" class="w-full rounded-lg border border-kasmin-200 px-3 py-3"></label>
        <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="privacy" value="1" required class="mt-1"> <span>{{ __('portal.privacy_consent') }}</span></label>
        @if ($askNewsletter)<label class="flex items-start gap-2 text-sm"><input type="checkbox" name="newsletter" value="1" class="mt-1"> <span>{{ __('public.newsletter_consent') }}</span></label>@endif
        <x-proof-of-work />
        <button class="w-full rounded-lg bg-kasmin-600 px-4 py-3 font-semibold text-white">{{ __('portal.register_submit') }}</button>
    </form>
    @endif
    <p class="mt-4 text-sm"><a class="underline" href="{{ route('login') }}">{{ __('portal.have_account') }}</a></p>
@endsection
