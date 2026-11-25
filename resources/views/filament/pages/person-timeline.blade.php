<x-filament-panels::page>
    <label class="text-sm">{{ __('people.timeline_type') }}<br>
        <select wire:model.live="type" class="rounded-lg border-gray-300 dark:bg-white/5">
            <option value="">{{ __('people.timeline_all') }}</option>
            @foreach (\App\Support\People\Timeline::TYPES as $t)<option value="{{ $t }}">{{ __('people.timeline_types.'.$t) }}</option>@endforeach
        </select>
    </label>
    <x-filament::section>
        <ul class="text-sm">
            @forelse ($items as $item)
                <li style="padding:.5rem 0;border-bottom:1px solid #e5e7eb;overflow-wrap:anywhere">
                    <span style="opacity:.7">{{ $item['at']->isoFormat('LLL') }} · {{ __('people.timeline_types.'.$item['type']) }}</span><br>
                    @if ($item['url'])<a class="underline" href="{{ $item['url'] }}">{{ $item['text'] }}</a>@else{{ $item['text'] }}@endif
                </li>
            @empty
                <li>{{ __('lists.empty') }}</li>
            @endforelse
        </ul>
    </x-filament::section>
</x-filament-panels::page>
