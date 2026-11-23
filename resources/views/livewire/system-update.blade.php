<div class="space-y-3 text-sm">
    <p>{{ __('system.update.installed', ['version' => $current]) }}</p>
    @if ($error)<p class="rounded-lg p-3" style="background:#fde2e2;color:#8a1c1c" role="alert">{{ $error }}</p>@endif
    @if ($precheck === null)
        <x-filament::button wire:click="check">{{ __('system.update.check') }}</x-filament::button>
    @else
        @if ($precheck['release'])
            <p>{{ __('system.update.latest', ['version' => $precheck['release']['version']]) }} <a class="underline" href="{{ $precheck['release']['url'] }}" target="_blank" rel="noopener">{{ __('system.update.notes') }}</a></p>
        @endif
        <ul class="space-y-1">
            @foreach ($precheck['checks'] as $c)<li style="color:{{ $c['ok'] ? '#14501c' : '#8a1c1c' }}">{{ $c['ok'] ? '✓' : '✗' }} {{ $c['label'] }}</li>@endforeach
        </ul>
        @if ($precheck['passes'] && $precheck['release'] && version_compare($precheck['release']['version'], preg_replace('/[-+].*$/', '', $current), '>'))
            <x-filament::button color="success" wire:click="update" wire:confirm="{{ __('system.update.confirm') }}">{{ __('system.update.start', ['version' => $precheck['release']['version']]) }}</x-filament::button>
            <p class="text-gray-500">{{ __('system.update.how') }}</p>
        @elseif (! $precheck['passes'])
            <p style="color:#8a1c1c">{{ __('system.update.precheck_failed') }}</p>
        @else
            <p>{{ __('system.update.up_to_date') }}</p>
        @endif
    @endif
</div>
