@extends('layouts.plain')
@section('title', __('automations.approval.title'))
@section('content')
    @if ($run === null)
        <h1 class="mb-2 text-2xl font-semibold">{{ __('automations.approval.invalid_title') }}</h1>
        <p>{{ __('automations.approval.invalid') }}</p>
    @else
        <h1 class="mb-1 text-2xl font-semibold">{{ $run->automation?->label() }}</h1>
        <p class="mb-4 text-kasmin-700">
            {{ __('automations.approval.intro', ['count' => count($run->plan)]) }}
            @if ($run->event) <br>{{ __('finance.event') }}: <strong>{{ $run->event->title }}</strong>@endif
            <br>{{ __('automations.approval.until', ['date' => $run->expires_at?->isoFormat('LLL')]) }}
        </p>
        @if ($errors->any())
            <div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-900" role="alert">{{ $errors->first() }}</div>
        @endif
        <form method="post" action="{{ route('approvals.decide', $token) }}" class="space-y-4">
            @csrf
            <fieldset>
                <legend class="mb-2 text-sm font-semibold">{{ __('automations.approval.items') }}</legend>
                <ul class="max-h-96 divide-y divide-kasmin-100 overflow-y-auto rounded-lg ring-1 ring-kasmin-100">
                    @foreach ($run->plan as $item)
                        <li class="p-2">
                            <label class="flex items-start gap-3">
                                <input type="checkbox" class="mt-1 h-5 w-5" name="included[]" value="{{ $item['key'] }}" checked
                                       data-key="{{ $item['key'] }}">
                                <span>{{ $item['label'] }}@if (! empty($item['details']))<span class="block text-sm text-kasmin-700">{{ $item['details'] }}</span>@endif</span>
                            </label>
                        </li>
                    @endforeach
                </ul>
                <p class="mt-1 text-xs text-kasmin-700">{{ __('automations.approval.deselect_hint') }}</p>
            </fieldset>
            <div id="excluded"></div>
            <label class="block">
                <span class="text-sm font-semibold">{{ __('automations.approval.comment') }}</span>
                <textarea name="comment" rows="2" maxlength="490" class="mt-1 w-full rounded-lg border-kasmin-200">{{ old('comment') }}</textarea>
            </label>
            @if ($needsCode)
                <label class="block">
                    <span class="text-sm font-semibold">{{ __('automations.approval.code') }}</span>
                    <input name="code" inputmode="numeric" autocomplete="one-time-code" required class="mt-1 w-full rounded-lg border-kasmin-200 text-lg tracking-widest">
                </label>
            @endif
            <div class="grid gap-3 sm:grid-cols-2">
                <button name="decision" value="approve" class="rounded-lg bg-kasmin-600 px-4 py-3 font-semibold text-white @if ($decision === 'approve') ring-4 ring-kasmin-200 @endif">{{ __('automations.approval.approve') }}</button>
                <button name="decision" value="deny" class="rounded-lg bg-white px-4 py-3 font-semibold text-red-800 ring-1 ring-red-200 @if ($decision === 'deny') ring-4 @endif">{{ __('automations.approval.deny') }}</button>
            </div>
        </form>
        <script>
            // Unticked items are sent as "excluded" (approve with changes, AT06); works without JavaScript too (all items).
            document.querySelector('form').addEventListener('submit', function () {
                var box = document.getElementById('excluded');
                document.querySelectorAll('input[name="included[]"]').forEach(function (input) {
                    if (!input.checked) {
                        var hidden = document.createElement('input');
                        hidden.type = 'hidden'; hidden.name = 'excluded[]'; hidden.value = input.dataset.key;
                        box.appendChild(hidden);
                    }
                });
            });
        </script>
    @endif
@endsection
