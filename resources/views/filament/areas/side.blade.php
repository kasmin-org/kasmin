{{-- The areas in the menu on the left: always on phones, and on the dashboard, which belongs to no area (B11). --}}
@php($areas = \App\Filament\Support\Areas::visible())
@php($none = \App\Filament\Support\Areas::current() === null)
@if ($areas !== [])
    <div @class(["mb-4 pt-1", "lg:hidden" => ! $none]) data-areas-menu>
        <p class="px-2 pb-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.areas.label') }}</p>
        <x-filament::tabs :label="__('admin.areas.label')" vertical>
            <x-filament::tabs.item tag="a" :href="\Filament\Facades\Filament::getUrl()" :active="$none && request()->routeIs('filament.admin.pages.dashboard')" icon="heroicon-o-home">
                {{ __('admin.areas.dashboard') }}
            </x-filament::tabs.item>
            @foreach ($areas as $key => $area)
                <x-filament::tabs.item tag="a" :href="$area['url']" :active="$area['active']" :icon="$area['icon']">
                    {{ $area['label'] }}
                </x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>
    </div>
@endif
