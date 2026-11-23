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
        <button class="w-full rounded-lg bg-kasmin-600 px-4 py-3 font-semibold text-white">{{ __('portal.save') }}</button>
    </form>
@endsection
