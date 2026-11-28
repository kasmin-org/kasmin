@extends('layouts.plain')
@section('title', $mail->subject)
@section('content')
    <p class="mb-4 text-sm"><a class="underline" href="{{ route('portal') }}">← {{ __('portal.title') }}</a></p>
    <h1 class="mb-1 text-xl font-semibold [overflow-wrap:anywhere]">{{ $mail->subject }}</h1>
    <p class="mb-4 text-sm text-kasmin-700">{{ $mail->created_at->isoFormat('LLL') }}</p>
    <div class="whitespace-pre-line text-sm [overflow-wrap:anywhere]">{{ \App\Support\Mail\MailLinks::until(\App\Support\Mail\MailLinks::replace($mail->text, route('portal')), null) }}</div>
@endsection
