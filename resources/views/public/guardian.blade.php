@extends('layouts.plain')
@section('title', __('portal.guardian_invitation'))
@section('content')
    <h1 class="mb-2 text-2xl font-semibold">{{ __('portal.guardian_invitation') }}</h1>
    @if ($done)
        <p role="status">{{ __('portal.guardian_done') }}</p>
        <p class="mt-4 text-sm"><a class="underline" href="{{ route('login') }}">{{ __('public.to_login') }}</a></p>
    @else
        <p class="mb-4">{{ __('portal.mail_guardian_text', ['host' => $host]) }}</p>
        <form method="post" action="{{ route('guardian.invitation.accept', $token) }}">@csrf
            <button class="w-full rounded-lg bg-kasmin-600 px-4 py-3 font-semibold text-white">{{ __('portal.guardian_accept') }}</button>
        </form>
    @endif
@endsection
