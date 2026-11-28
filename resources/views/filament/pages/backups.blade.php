<x-filament-panels::page>
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

</x-filament-panels::page>
