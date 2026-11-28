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
                        @if (! empty($check['items']))
                            <details class="mt-1 text-sm" data-requirements>
                                <summary class="cursor-pointer underline">{{ __('system.requirements.show') }}</summary>
                                <table class="mt-2" style="border-collapse:collapse">
                                    @foreach ($check['items'] as $item)
                                        <tr style="border-top:1px solid rgba(128,128,128,.2)">
                                            <td style="padding:.25rem .75rem .25rem 0;white-space:nowrap;color:{{ $item['ok'] ? '#15803d' : ($item['required'] ? '#b91c1c' : '#b45309') }};font-weight:600">{{ $item['ok'] ? '✓' : '✗' }}</td>
                                            <td style="padding:.25rem .75rem .25rem 0;font-family:ui-monospace,monospace;white-space:nowrap">{{ $item['name'] }}</td>
                                            <td style="padding:.25rem 0;overflow-wrap:anywhere">{{ $item['purpose'] }}@if (! $item['required']) <span class="text-gray-500">({{ __('system.requirements.recommended') }})</span>@endif</td>
                                        </tr>
                                    @endforeach
                                </table>
                            </details>
                        @endif
                    </div>
                    <span class="rounded-full px-2 py-0.5 text-xs font-semibold" style="{{ $badge($check['status']) }}">{{ __('system.status.levels.'.$check['status']) }}</span>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section :heading="__('system.phpinfo.title')" :description="__('system.phpinfo.intro')" collapsible collapsed>
        <div data-phpinfo x-data="{ shown: false }">
            <x-filament::button color="gray" x-on:click="shown = ! shown">{{ __('system.phpinfo.show') }}</x-filament::button>
            <a class="ml-3 text-sm underline" href="{{ route('admin.phpinfo') }}" target="_blank" rel="noopener">{{ __('system.phpinfo.new_tab') }}</a>
            <template x-if="shown">
                <iframe src="{{ route('admin.phpinfo') }}" title="phpinfo" style="width:100%;height:70vh;border:1px solid rgba(128,128,128,.3);border-radius:.5rem;margin-top:.75rem;background:#fff"></iframe>
            </template>
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

</x-filament-panels::page>
