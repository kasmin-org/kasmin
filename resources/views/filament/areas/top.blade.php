{{-- The five areas at the top (B11); on phones they are at the top of the menu instead. --}}
@php($areas = \App\Filament\Support\Areas::visible())
@if ($areas !== [])
    <x-filament::tabs :label="__('admin.areas.label')" class="hidden lg:flex ms-2" data-areas>
        <x-filament::tabs.item tag="a" :href="\Filament\Facades\Filament::getUrl()" :active="\App\Filament\Support\Areas::current() === null && request()->routeIs('filament.admin.pages.dashboard')">
            {{ __('admin.areas.dashboard') }}
        </x-filament::tabs.item>
        @foreach ($areas as $key => $area)
            <x-filament::tabs.item tag="a" :href="$area['url']" :active="$area['active']" :icon="$area['icon']" data-area="{{ $key }}">
                {{ $area['label'] }}
            </x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>
@endif
