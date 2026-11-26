@extends('layouts.plain')
@section('title', $title)
@section('content')
    <h1 class="mb-4 text-2xl font-semibold">{{ $title }}</h1>
    @if ($action === null)<p role="status">{{ $text }}</p>
        <p class="mt-4 text-sm"><a class="underline" href="{{ route('login') }}">{{ __('public.to_login') }}</a></p>
    @else
        @isset($intro)<p class="mb-4" data-confirm-intro style="overflow-wrap:anywhere">{{ $intro }}</p>@endisset
        <form method="post" action="{{ $action }}">@csrf<button class="w-full rounded-lg bg-kasmin-600 px-4 py-3 font-semibold text-white">{{ __('public.confirm') }}</button></form>@endif
@endsection
