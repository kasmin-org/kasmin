<x-filament-panels::page>
    <div style="display:flex;flex-wrap:wrap;gap:1rem;align-items:end">
        @if ($real)
            <label class="text-sm">{{ __('admin.mail.preview_person') }}<br>
                <select wire:model.live="person" data-preview-person class="rounded-lg border-gray-300 dark:bg-white/5" style="max-width:100%"><option value="">{{ __('admin.mail.preview_sample') }}</option>
                    @foreach ($people as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
            </label>
            @if ($morePeople)
                <label class="text-sm">{{ __('admin.mail.preview_search') }}<br>
                    <input type="search" wire:model.live.debounce.400ms="search" class="rounded-lg border-gray-300 dark:bg-white/5" style="max-width:100%;border-width:1px;padding:.4rem .6rem">
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
    @if ($preview['error'] !== null)
        <p role="alert" data-template-error style="color:#b91c1c;font-weight:600;border:1px solid #b91c1c;border-radius:.5rem;padding:.75rem">{{ $preview['error'] }}</p>
    @else
        <x-filament::section :heading="$preview['subject']">
            <div style="{{ $width === 'phone' ? 'max-width:375px' : '' }}" wire:key="frame-{{ $width }}-{{ $person }}-{{ $event }}">{{ $preview['frame'] }}</div>
            @if ($maySend)<x-filament::button size="sm" icon="heroicon-o-paper-airplane" style="margin-top:.75rem" wire:click="test">{{ __('admin.mail.test_self') }}</x-filament::button>@endif
            <details style="margin-top:.75rem"><summary class="text-sm">{{ __('admin.mail.text_part') }}</summary><pre class="text-sm" style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $preview['text'] }}</pre></details>
        </x-filament::section>
    @endif
</x-filament-panels::page>
