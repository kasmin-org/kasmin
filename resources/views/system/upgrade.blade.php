@extends('layouts.plain')
@section('title', __('system.upgrade.title'))
@section('content')
    <h1 class="mb-2 text-2xl font-semibold">{{ __('system.upgrade.title') }}</h1>
    @if ($done)
        <div class="mb-4 rounded-lg bg-kasmin-100 p-3" role="status" data-upgrade-done>{{ __('system.upgrade.done', ['version' => $codeVersion]) }}</div>
        <a class="block w-full rounded-lg bg-kasmin-600 px-4 py-3 text-center font-semibold text-white" href="/admin">{{ __('system.upgrade.continue') }}</a>
    @else
        @if ($failed)<div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-800" role="alert">{{ __('system.upgrade.failed') }}</div>@endif
        <p class="mb-2">{{ __('system.upgrade.text') }}</p>
        <p class="mb-4 text-sm text-kasmin-700">{{ __('system.schema.versions', ['code' => $codeVersion, 'database' => $databaseVersion ?? '–']) }} · {{ trans_choice('system.upgrade.steps', $pending, ['count' => $pending]) }}</p>
        <ul class="mb-6 list-disc space-y-1 pl-5 text-sm">
            <li>{{ __('system.upgrade.backup') }}</li>
            <li>{{ __('system.upgrade.wait') }}</li>
        </ul>
        <form method="post" action="{{ route('system.upgrade.run') }}" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='{{ __('system.upgrade.running') }}'">
            @csrf
            <button class="w-full rounded-lg bg-kasmin-600 px-4 py-3 font-semibold text-white hover:bg-kasmin-700">{{ __($failed ? 'system.upgrade.again' : 'system.upgrade.start') }}</button>
        </form>
    @endif
    @if ($output !== '')
        <details class="mt-4 text-sm" @if ($failed) open @endif><summary class="cursor-pointer underline">{{ __('system.upgrade.details') }}</summary>
            <pre class="mt-2 overflow-x-auto whitespace-pre-wrap rounded-lg bg-kasmin-50 p-3 text-xs">{{ $output }}</pre></details>
    @endif
@endsection
