@extends('layouts.plain')
@section('title', $title ?? '')
@section('content')
    <h1 class="mb-2 text-2xl font-semibold">{{ $title }}</h1>
    <p role="status">{{ $status === 'failed' ? __('public.failed', ['reason' => $note]) : __($status === 'pending' ? 'public.pending' : 'public.done') }}</p>
    <p class="mt-4 text-sm"><a class="underline" href="{{ route('login') }}">{{ __('public.to_login') }}</a></p>
@endsection
