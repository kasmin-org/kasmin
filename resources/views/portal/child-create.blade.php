@extends('layouts.plain')
@section('title', __('portal.add_child'))
@section('content')
    <p class="mb-4 text-sm"><a class="underline" href="{{ route('portal') }}">← {{ __('portal.title') }}</a></p>
    <h1 class="mb-4 text-2xl font-semibold">{{ __('portal.add_child') }}</h1>
    @if ($errors->any())<div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-800" role="alert">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <form method="post" action="{{ route('portal.child.store') }}" class="space-y-4">
        @csrf
        <label class="block"><span class="mb-1 block text-sm font-medium">{{ __('admin.person.first_name') }}</span>
            <input name="first_name" required maxlength="120" value="{{ old('first_name') }}" class="w-full rounded-lg border border-kasmin-200 px-3 py-3"></label>
        <label class="block"><span class="mb-1 block text-sm font-medium">{{ __('admin.person.last_name') }}</span>
            <input name="last_name" required maxlength="120" value="{{ old('last_name') }}" class="w-full rounded-lg border border-kasmin-200 px-3 py-3"></label>
        <label class="block"><span class="mb-1 block text-sm font-medium">{{ __('admin.person.birth_date') }}</span>
            <input type="date" name="birth_date" required value="{{ old('birth_date') }}" max="{{ now()->toDateString() }}" class="w-full rounded-lg border border-kasmin-200 px-3 py-3"></label>
        <div>
            <label class="block"><span class="mb-1 block text-sm font-medium">{{ __('admin.person.gender') }}</span>
                {{-- The note of the chosen gender (setting, PE09) appears below; without JavaScript the one of the last choice. --}}
                <select name="gender" required class="w-full rounded-lg border border-kasmin-200 bg-white px-3 py-3"
                    onchange="document.querySelectorAll('[data-gender-note]').forEach((n) => { n.hidden = n.dataset.genderNote !== this.value })">
                    <option value="" disabled @selected(old('gender') === null)>{{ __('portal.please_choose') }}</option>
                    @foreach (['female', 'male', 'diverse'] as $key)<option value="{{ $key }}" @selected(old('gender') === $key)>{{ __('admin.genders.'.$key) }}</option>@endforeach
                </select></label>
            @foreach ($genderNotes as $key => $note)
                <p data-gender-note="{{ $key }}" @if (old('gender') !== $key) hidden @endif class="mt-2 rounded-lg bg-kasmin-50 p-3 text-sm" role="note">{!! nl2br(e($note)) !!}</p>
            @endforeach
        </div>
        <button class="w-full rounded-lg bg-kasmin-600 px-4 py-3 font-semibold text-white">{{ __('portal.save') }}</button>
    </form>
@endsection
