<div class="space-y-3 text-sm">
    <p>{{ __('system.update.installed', ['version' => $current]) }}</p>
    @if ($error)<p class="rounded-lg p-3" style="background:#fde2e2;color:#8a1c1c" role="alert">{{ $error }}</p>@endif
    @if ($precheck === null)
        <x-filament::button wire:click="check">{{ __('system.update.check') }}</x-filament::button>
    @elseif ($versions === [])
        <p data-update-none>{{ __('system.update.up_to_date') }}</p>
    @else
        <label class="block" style="max-width:28rem">
            <span class="mb-1 block font-medium">{{ trans_choice('system.update.choose', count($versions), ['count' => count($versions)]) }}</span>
            <select wire:model.live="target" data-update-target class="w-full rounded-lg border-gray-300 dark:bg-white/5" style="border-width:1px;padding:.4rem .6rem" @disabled(count($versions) === 1)>
                @foreach ($versions as $version => $label)<option value="{{ $version }}">{{ $label }}</option>@endforeach
            </select>
            @if (count($versions) > 1)<span class="mt-1 block text-gray-500">{{ __('system.update.choose_hint') }}</span>@endif
        </label>

        <div data-update-changes wire:key="changes-{{ $target }}">
            <p class="font-medium">{{ __('system.update.changes', ['from' => $current, 'to' => $target]) }}</p>
            @if ($changes !== '')
                <div class="kasmin-changelog rounded-lg p-3" style="border:1px solid rgba(128,128,128,.3);max-height:28rem;overflow:auto">{!! $changes !!}</div>
            @else
                <p class="text-gray-500">{{ __('system.update.changes_missing') }} @if ($precheck['release'])<a class="underline" href="{{ $precheck['release']['url'] }}" target="_blank" rel="noopener">{{ __('system.update.notes') }}</a>@endif</p>
            @endif
        </div>

        <details>
            <summary class="cursor-pointer underline">{{ __('system.update.requirements', ['version' => $target]) }} – {{ $precheck['passes'] ? __('system.update.requirements_ok') : __('system.update.requirements_failed') }}</summary>
            <ul class="mt-2 space-y-1">
                @foreach ($precheck['checks'] as $c)<li style="color:{{ $c['ok'] ? '#14501c' : '#8a1c1c' }}">{{ $c['ok'] ? '✓' : '✗' }} {{ $c['label'] }}</li>@endforeach
            </ul>
        </details>
        @if ($precheck['passes'] && $precheck['release'])
            <x-filament::button color="success" wire:click="update" wire:confirm="{{ __('system.update.confirm') }}" wire:key="start-{{ $target }}">{{ __('system.update.start', ['version' => $target]) }}</x-filament::button>
            <p class="text-gray-500">{{ __('system.update.how') }}</p>
        @else
            <p style="color:#8a1c1c">{{ __('system.update.precheck_failed') }}</p>
        @endif
    @endif
    <style>.kasmin-changelog h3{font-size:1.05rem;font-weight:700;margin:.75rem 0 .25rem}.kasmin-changelog h3:first-child{margin-top:0}.kasmin-changelog h4{font-weight:600;margin:.5rem 0 .15rem}.kasmin-changelog ul{list-style:disc;padding-left:1.25rem}.kasmin-changelog li{margin:.2rem 0}.kasmin-changelog code{font-family:ui-monospace,monospace;font-size:.9em}</style>
</div>
