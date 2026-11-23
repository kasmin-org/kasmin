@extends('layouts.plain')
@section('title', __('automations.approval.title'))
@section('content')
    <h1 class="mb-2 text-2xl font-semibold">{{ __('automations.approval.thanks') }}</h1>
    <p>{{ $status }}</p>
@endsection
