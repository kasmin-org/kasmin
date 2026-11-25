<x-filament-panels::page>
    <x-filament::section :heading="__('events.season.step1')">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,220px),1fr));gap:1rem">
            <label class="text-sm">{{ __('events.season.source') }}<br>
                <select wire:model.live="source" class="rounded-lg border-gray-300 dark:bg-white/5" style="width:100%;border-width:1px;padding:.45rem .6rem">@foreach ($events as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
            </label>
            <label class="text-sm">{{ __('admin.event.title') }}<br>
                <input type="text" wire:model.blur="newTitle" maxlength="200" class="rounded-lg border-gray-300 dark:bg-white/5" style="width:100%;border-width:1px;padding:.45rem .6rem">@error('newTitle')<span style="color:#b91c1c">{{ $message }}</span>@enderror
            </label>
            <label class="text-sm">{{ __('admin.event.starts_at') }}<br>
                <input type="date" wire:model.blur="startsOn" class="rounded-lg border-gray-300 dark:bg-white/5" style="width:100%;border-width:1px;padding:.45rem .6rem">@error('startsOn')<span style="color:#b91c1c">{{ $message }}</span>@enderror
            </label>
        </div>
        <x-filament::button style="margin-top:1rem" icon="heroicon-o-eye" wire:click="check">{{ __('events.season.preview') }}</x-filament::button>
    </x-filament::section>
    @if ($preview)
        <x-filament::section :heading="__('events.season.step2')">
            <p class="text-sm">{{ __('events.season.nothing_yet') }}</p>
            <h3 style="font-weight:600;margin-top:.75rem">{{ $preview['title'] }}: {{ $preview['starts_at']->isoFormat('LL') }} – {{ $preview['ends_at']->isoFormat('LL') }}</h3>
            <p class="text-sm">{{ implode(' · ', $preview['roles']) }}</p>
            <p class="text-sm">{{ __('events.season.copied') }}: @foreach ($preview['counts'] as $key => $count){{ $loop->first ? '' : ', ' }}{{ __('events.season.'.$key) }}: {{ $count }}@endforeach</p>
            <h3 style="font-weight:600;margin-top:.75rem">{{ __('automations.schedule.title') }}</h3>
            <ul class="text-sm">@foreach ($preview['dates'] as $d)<li>{{ $d['at']->isoFormat('dd L') }} – {{ $d['what'] }}</li>@endforeach</ul>
            <h3 style="font-weight:600;margin-top:.75rem">{{ __('events.season.people', ['count' => count($preview['people'])]) }}</h3>
            <ul class="text-sm" style="max-height:20rem;overflow:auto">@forelse ($preview['people'] as $p)<li>{{ $p['label'] }}</li>@empty<li>–</li>@endforelse</ul>
            @if ($mayStart && $preview['people'] !== [])
                <label class="text-sm" style="display:flex;gap:.5rem;align-items:center;margin-top:.75rem"><input type="checkbox" wire:model.boolean="season"> {{ __('events.season.start') }}</label>
                <p class="text-sm" style="opacity:.75">{{ __('events.season.start_hint') }}</p>
            @endif
            <x-filament::button style="margin-top:1rem" color="success" icon="heroicon-o-check" wire:click="create" wire:confirm="{{ __('events.season.confirm') }}">{{ __('events.season.create') }}</x-filament::button>
        </x-filament::section>
    @endif
</x-filament-panels::page>
