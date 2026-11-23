@php
    $badge = fn ($s) => match ($s) { 'ok' => 'background:#dcf1dd;color:#14501c', 'warning' => 'background:#fdf0d5;color:#7a4b00', 'error' => 'background:#fde2e2;color:#8a1c1c', default => 'background:#e8eef6;color:#1f3b63' };
@endphp
<x-filament-panels::page>
    <x-filament::section :heading="__('system.status.checks')">
        <div class="divide-y divide-gray-200 dark:divide-white/10">
            @foreach ($checks as $check)
                <div class="flex flex-wrap items-baseline justify-between gap-2 py-2">
                    <div>
                        <span class="font-medium">{{ $check['label'] }}</span>
                        @if ($check['detail'])<span class="text-sm text-gray-500"> – {{ $check['detail'] }}</span>@endif
                        @if (! empty($check['url']))<a class="text-sm underline" href="{{ $check['url'] }}" target="_blank" rel="noopener">{{ __('system.status.docs') }}</a>@endif
                    </div>
                    <span class="rounded-full px-2 py-0.5 text-xs font-semibold" style="{{ $badge($check['status']) }}">{{ __('system.status.levels.'.$check['status']) }}</span>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section :heading="__('system.dns.title')" :description="__('system.dns.intro')">
        @forelse ($dns as $row)
            <h3 class="mt-2 font-semibold">{{ $row['domain'] }} <span class="text-sm font-normal text-gray-500">({{ $row['account'] }}{{ $row['direct'] ? ', '.__('system.dns.direct') : '' }})</span></h3>
            @foreach ($row['checks'] as $c)
                <div class="flex flex-wrap items-start justify-between gap-2 border-b border-gray-100 py-2 dark:border-white/10">
                    <div class="min-w-0">
                        <span class="font-medium">{{ $c['record'] }}</span>
                        @if ($c['found'])<code class="block break-all text-xs text-gray-500">{{ $c['found'] }}</code>@endif
                        @if ($c['detail'])<p class="text-sm">{{ $c['detail'] }}</p>@endif
                        @if ($c['suggestion'])<p class="text-sm">{{ __('system.dns.suggestion') }}</p><code class="block break-all rounded bg-gray-100 p-2 text-xs dark:bg-white/5">{{ $c['suggestion'] }}</code>@endif
                    </div>
                    <span class="rounded-full px-2 py-0.5 text-xs font-semibold" style="{{ $badge($c['status']) }}">{{ __('system.status.levels.'.$c['status']) }}</span>
                </div>
            @endforeach
        @empty
            <p class="text-sm">{{ __('system.dns.none') }}</p>
        @endforelse
    </x-filament::section>

    <x-filament::section :heading="__('system.update.title')">
        @if ($docker)
            <p class="rounded-lg p-3 text-sm" style="background:#fdf0d5;color:#7a4b00">{{ __('system.update.docker') }}</p>
        @else
            @livewire(\App\Livewire\SystemUpdate::class)
        @endif
    </x-filament::section>
</x-filament-panels::page>
