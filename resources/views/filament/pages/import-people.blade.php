<x-filament-panels::page>
    <div class="space-y-6">
        {{ $this->form }}
        <div class="flex flex-wrap gap-3">
            <x-filament::button wire:click="read" icon="heroicon-o-document-magnifying-glass">{{ __('import.read') }}</x-filament::button>
            @if ($columns !== [])
                <x-filament::button wire:click="preview" color="gray" icon="heroicon-o-eye">{{ __('import.preview') }}</x-filament::button>
            @endif
            @if ($plan !== null && ($plan['summary']['create'] + $plan['summary']['update']) > 0)
                <x-filament::button wire:click="import" color="success" icon="heroicon-o-arrow-down-tray"
                    wire:confirm="{{ __('import.confirm', ['create' => $plan['summary']['create'], 'update' => $plan['summary']['update']]) }}">{{ __('import.import') }}</x-filament::button>
            @endif
        </div>

        @if ($plan !== null)
            <x-filament::section :heading="__('import.result')">
                <p class="mb-3 text-sm">{{ __('import.summary', $plan['summary']) }}</p>
                <div class="max-h-96 overflow-auto">
                    <table class="w-full text-sm">
                        <thead><tr class="text-left"><th class="p-1">#</th><th class="p-1">{{ __('import.action') }}</th><th class="p-1">{{ __('import.name') }}</th><th class="p-1">{{ __('import.messages') }}</th></tr></thead>
                        <tbody>
                        @foreach ($plan['rows'] as $row)
                            <tr class="border-t border-gray-200 dark:border-white/10 {{ $row['action'] === 'error' ? 'text-danger-600' : '' }}">
                                <td class="p-1">{{ $row['row'] }}</td>
                                <td class="p-1">{{ __('import.actions.'.$row['action']) }}</td>
                                <td class="p-1">{{ $row['name'] }}</td>
                                <td class="p-1">{{ implode('; ', $row['messages']) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
            <x-filament::section :heading="__('import.families')" :description="__('import.families_hint')">
                @forelse ($plan['families'] as $family)
                    <div class="border-t border-gray-200 py-2 text-sm dark:border-white/10">
                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $family['confidence'] === 'high' ? 'bg-success-100 text-success-800' : 'bg-warning-100 text-warning-800' }}">{{ __('import.confidence.'.$family['confidence']) }}</span>
                        {{ implode(', ', $family['names']) }} <span class="text-gray-500">({{ __('import.rows') }} {{ implode(', ', $family['rows']) }} · {{ implode(', ', $family['reasons']) }})</span>
                    </div>
                @empty
                    <p class="text-sm">{{ __('import.no_families') }}</p>
                @endforelse
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
