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

    <x-filament::section :heading="__('system.backup.title')" :description="__('system.backup.intro')">
        <div data-backups class="text-sm">
            @if ($remote !== null)
                <p class="mb-3 rounded-lg p-3" style="background:{{ $remote['ok'] === false ? '#fde2e2;color:#7f1d1d' : '#e8f5e9;color:#14501c' }}">
                    {{ $remote['ok'] === null ? __('system.backup.remote_never') : ($remote['ok'] ? __('system.backup.remote_ok', ['time' => \Illuminate\Support\Carbon::parse($remote['at'])->diffForHumans()]) : __('system.backup.remote_failed', ['time' => \Illuminate\Support\Carbon::parse($remote['at'])->diffForHumans(), 'error' => $remote['error']])) }}
                </p>
            @else
                <p class="mb-3 rounded-lg p-3" style="background:#fdf0d5;color:#7a4b00">{{ __('system.backup.remote_off') }}</p>
            @endif
            <form method="post" action="{{ route('admin.backups.create') }}" class="mb-3">@csrf
                <x-filament::button type="submit" color="gray">{{ __('system.backup.now') }}</x-filament::button>
            </form>
            @forelse ($backups as $b)
                <div style="display:flex;flex-wrap:wrap;gap:.25rem 1rem;padding:.35rem 0;border-bottom:1px solid rgba(0,0,0,.06)">
                    <span style="flex:1 1 16rem;min-width:0;overflow-wrap:anywhere">{{ $b['name'] }}</span>
                    <span>{{ $b['at']->isoFormat('L LT') }} · {{ number_format($b['size'] / 1048576, 1) }} MB</span>
                    <span><a class="underline" href="{{ route('admin.backups.download', $b['name']) }}">{{ __('system.backup.download') }}</a>@if ($passphrase) · <a class="underline" href="{{ route('admin.backups.download', [$b['name'], 'encrypted' => 1]) }}">{{ __('system.backup.download_encrypted') }}</a>@endif</span>
                </div>
            @empty
                <p>{{ __('system.status.backup_none') }}</p>
            @endforelse
            <details class="mt-4">
                <summary class="cursor-pointer font-semibold">{{ __('system.backup.decrypt') }}</summary>
                <p class="my-2">{{ __('system.backup.decrypt_hint') }}</p>
                <form method="post" action="{{ route('admin.backups.decrypt') }}" enctype="multipart/form-data" style="display:grid;gap:.5rem;max-width:28rem">@csrf
                    <input type="file" name="file" required accept=".enc">
                    <input type="password" name="passphrase" autocomplete="off" placeholder="{{ __('system.backup.passphrase_optional') }}" style="border:1px solid rgba(128,128,128,.45);border-radius:.5rem;padding:.5rem .75rem;background:transparent">
                    <div><x-filament::button type="submit" color="gray">{{ __('system.backup.decrypt_button') }}</x-filament::button></div>
                </form>
            </details>
        </div>
    </x-filament::section>

    <x-filament::section :heading="__('system.update.title')">
        @if ($docker)
            <p class="rounded-lg p-3 text-sm" style="background:#fdf0d5;color:#7a4b00">{{ __('system.update.docker') }}</p>
        @else
            @livewire(\App\Livewire\SystemUpdate::class)
        @endif
    </x-filament::section>
</x-filament-panels::page>
