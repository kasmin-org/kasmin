@extends('layouts.plain')
@section('title', __('mail.preferences_title'))
@section('content')
    @if (session('status'))<div class="mb-4 rounded-lg bg-kasmin-100 p-3 text-sm" role="status">{{ session('status') }}</div>@endif
    <h1 class="mb-2 text-2xl font-semibold">{{ __('mail.preferences_title') }}</h1>
    <p class="mb-6 text-kasmin-700">{{ __('mail.preferences_intro', ['name' => $person->fullName()]) }}</p>
    <div class="space-y-3">
        @foreach ([($newsletter ? 'newsletter_off' : 'newsletter_on'), 'optout_all', 'deletion_request'] as $choice)
            <form method="post" action="{{ $action }}" @if ($choice === 'deletion_request') onsubmit="return confirm('{{ __('mail.confirm_deletion') }}')" @endif>
                @csrf
                <input type="hidden" name="choice" value="{{ $choice }}">
                <button class="w-full rounded-lg border border-kasmin-200 px-4 py-3 text-left font-medium hover:border-kasmin-500">{{ __('mail.choices.'.$choice) }}</button>
            </form>
        @endforeach
    </div>
    <p class="mt-6 text-sm text-kasmin-700">{{ __('mail.preferences_note') }}</p>
@endsection
