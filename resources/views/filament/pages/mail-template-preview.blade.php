<x-filament-panels::page>
    <div style="display:flex;flex-wrap:wrap;gap:1rem;align-items:end">
        @if ($real)
            <label class="text-sm">{{ __('admin.mail.preview_person') }}<br>
                <input type="search" wire:model.live.debounce.400ms="search" placeholder="{{ __('admin.mail.preview_search') }}" class="rounded-lg border-gray-300 dark:bg-white/5" style="max-width:100%;border-width:1px;padding:.4rem .6rem">
            </label>
            @if ($people !== [])
                <label class="text-sm">{{ __('admin.mail.preview_result') }}<br>
                    <select wire:model.live="person" class="rounded-lg border-gray-300 dark:bg-white/5" style="max-width:100%"><option value="">–</option>
                        @foreach ($people as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                </label>
            @endif
        @endif
        <label class="text-sm">{{ __('admin.event.singular') }}<br>
            <select wire:model.live="event" class="rounded-lg border-gray-300 dark:bg-white/5" style="max-width:100%"><option value="">{{ __('admin.mail.preview_sample') }}</option>
                @foreach ($events as $id => $title)<option value="{{ $id }}">{{ $title }}</option>@endforeach
            </select>
        </label>
        <label class="text-sm">{{ __('admin.mail.preview_width') }}<br>
            <select wire:model.live="width" class="rounded-lg border-gray-300 dark:bg-white/5">
                <option value="desktop">{{ __('admin.mail.preview_desktop') }}</option><option value="phone">{{ __('admin.mail.preview_phone') }}</option>
            </select>
        </label>
    </div>
    <p class="text-sm">{{ $chosen !== null ? __('admin.mail.preview_for', ['name' => $chosen]) : __('admin.mail.preview_example') }} {{ __('admin.mail.preview_links') }}</p>
    @foreach ($previews as $locale => $preview)
        <x-filament::section :heading="strtoupper($locale).': '.$preview['subject']">
            <div style="{{ $width === 'phone' ? 'max-width:375px' : '' }}" wire:key="frame-{{ $locale }}-{{ $width }}-{{ $person }}-{{ $event }}">{{ $preview['frame'] }}</div>
            @if ($maySend)<x-filament::button size="sm" icon="heroicon-o-paper-airplane" style="margin-top:.75rem" wire:click="test('{{ $locale }}')">{{ __('admin.mail.test_self') }}</x-filament::button>@endif
            <details style="margin-top:.75rem"><summary class="text-sm">{{ __('admin.mail.text_part') }}</summary><pre class="text-sm" style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $preview['text'] }}</pre></details>
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
