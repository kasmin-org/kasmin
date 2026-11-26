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
                                            <td style="padding:.25rem .75rem .25rem 0;font-family:ui-monospace,monospace;overflow-wrap:anywhere">{{ $item['name'] }}</td>
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
            @if ($restore)
                <details class="mt-4" data-restore>
                    <summary class="cursor-pointer font-semibold">{{ __('system.restore.title') }}</summary>
                    <p class="my-2 rounded-lg p-3" style="background:#fde2e2;color:#7f1d1d">{{ __('system.restore.warning') }}</p>
                    <form method="post" action="{{ route('admin.backups.restore') }}" enctype="multipart/form-data" style="display:grid;gap:.5rem;max-width:28rem">@csrf
                        <label>{{ __('system.restore.from_list') }}
                            <select name="name" style="display:block;width:100%;border:1px solid rgba(128,128,128,.45);border-radius:.5rem;padding:.5rem .75rem;background:transparent">
                                <option value="">–</option>
                                @foreach ($backups as $b)<option value="{{ $b['name'] }}">{{ $b['name'] }} · {{ $b['at']->isoFormat('L LT') }}</option>@endforeach
                            </select></label>
                        <label>{{ __('system.restore.or_file') }}<input type="file" name="file" accept=".gz,.enc" style="display:block"></label>
                        <input type="password" name="passphrase" autocomplete="off" placeholder="{{ __('system.backup.passphrase_optional') }}" style="border:1px solid rgba(128,128,128,.45);border-radius:.5rem;padding:.5rem .75rem;background:transparent">
                        <label>{!! __('system.restore.type_word', ['word' => '<strong>'.e(__('system.restore.word')).'</strong>']) !!}
                            <input name="confirm" required autocomplete="off" style="display:block;width:100%;border:1px solid rgba(128,128,128,.45);border-radius:.5rem;padding:.5rem .75rem;background:transparent"></label>
                        <div><x-filament::button type="submit" color="danger">{{ __('system.restore.button') }}</x-filament::button></div>
                    </form>
                </details>
            @endif
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
