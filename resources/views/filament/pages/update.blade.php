<x-filament-panels::page>
    <x-filament::section :heading="__('system.update.title')">
        @if ($docker)
            <p class="rounded-lg p-3 text-sm" style="background:#fdf0d5;color:#7a4b00">{{ __('system.update.docker') }}</p>
        @else
            @livewire(\App\Livewire\SystemUpdate::class)
        @endif
    </x-filament::section>
</x-filament-panels::page>
