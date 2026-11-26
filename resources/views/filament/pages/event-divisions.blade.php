@php
    $input = 'border:1px solid rgba(128,128,128,.4);border-radius:.4rem;padding:.2rem .4rem;background:transparent;font-size:.85rem;max-width:100%';
    $short = fn (array $p) => trim(($p['age'] !== null ? $p['age'] : '?').' '.($p['gender'] !== '' ? mb_substr(__('admin.genders.'.$p['gender']), 0, 1) : ''));
@endphp
<x-filament-panels::page>
    @if ($divisions === [])
        <x-filament::section><p class="text-sm">{{ __('events.divisions.empty') }}</p></x-filament::section>
    @endif
    @foreach ($divisions as $d)
        <x-filament::section :heading="$d['model']->name" :description="__('events.divisions.kinds.'.$d['model']->kind).' · '.__('events.divisions.rule_'.($d['model']->option('separate_gender') ? 'separate' : ($d['model']->option('balance_gender') ? 'mixed' : 'any'))).' · '.__('events.divisions.siblings_options.'.$d['model']->option('siblings'))">
            <div data-division="{{ $d['model']->id }}" data-division-name="{{ $d['model']->name }}" class="text-sm">
                @foreach ($d['warnings'] as $w)
                    <p class="mb-2 rounded-lg p-2" style="background:#fdf0d5;color:#7a4b00">{{ $w }}</p>
                @endforeach
                @if ($edit)
                    <div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1rem">
                        <x-filament::button size="sm" wire:click="propose({{ $d['model']->id }})">{{ __('events.divisions.propose') }}</x-filament::button>
                        <x-filament::button size="sm" color="gray" wire:click="propose({{ $d['model']->id }}, true)" wire:confirm="{{ __('events.divisions.restart_confirm') }}">{{ __('events.divisions.restart') }}</x-filament::button>
                        <x-filament::button size="sm" color="gray" wire:click="addUnit({{ $d['model']->id }})">{{ __('events.divisions.add_unit') }}</x-filament::button>
                        <x-filament::button size="sm" color="danger" outlined wire:click="remove({{ $d['model']->id }})" wire:confirm="{{ __('events.divisions.delete_confirm') }}">{{ __('events.divisions.delete') }}</x-filament::button>
                    </div>
                @endif
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,17rem),1fr));gap:1rem">
                    @foreach ($d['units'] as $u)
                        <div style="border:1px solid rgba(128,128,128,.3);border-radius:.6rem;padding:.6rem" data-unit="{{ $u['model']->id }}">
                            @if ($edit)
                                <input value="{{ $u['model']->name }}" wire:change="unit({{ $u['model']->id }}, 'name', $event.target.value)" aria-label="{{ __('events.divisions.name') }}" style="{{ $input }};font-weight:600;width:100%">
                                <div style="display:flex;gap:.4rem;margin:.3rem 0">
                                    <input value="{{ $u['model']->note }}" wire:change="unit({{ $u['model']->id }}, 'note', $event.target.value)" placeholder="{{ __('events.divisions.note') }}" aria-label="{{ __('events.divisions.note') }}" style="{{ $input }};flex:1 1 auto;min-width:0">
                                    <input value="{{ $u['model']->capacity }}" inputmode="numeric" wire:change="unit({{ $u['model']->id }}, 'capacity', $event.target.value)" placeholder="{{ __('events.divisions.max') }}" aria-label="{{ __('events.divisions.capacity') }}" style="{{ $input }};width:4rem">
                                </div>
                            @else
                                <p style="font-weight:600">{{ $u['model']->name }}</p>
                                @if ($u['model']->note)<p class="text-gray-500">{{ $u['model']->note }}</p>@endif
                            @endif
                            <p class="text-gray-500" style="margin-bottom:.3rem">{{ count($u['people']) }}{{ $u['model']->capacity ? ' / '.$u['model']->capacity : '' }}</p>
                            @foreach ($u['people'] as $p)
                                <div style="display:flex;flex-wrap:wrap;gap:.25rem .5rem;align-items:center;padding:.2rem 0;border-top:1px solid rgba(128,128,128,.15)">
                                    <span style="flex:1 1 9rem;min-width:0;overflow-wrap:anywhere">{{ $p['name'] }} <span class="text-gray-500">{{ $short($p) }}{{ $p['role'] !== 'child' ? ' · '.__('events.roles.'.$p['role']) : '' }}</span></span>
                                    @if ($edit)
                                        <select wire:change="move({{ $d['model']->id }}, {{ $p['id'] }}, $event.target.value)" aria-label="{{ __('events.divisions.move') }}" style="{{ $input }}">
                                            @foreach ($d['units'] as $o)<option value="{{ $o['model']->id }}" @selected($o['model']->id === $u['model']->id)>{{ $o['model']->name }}</option>@endforeach
                                            <option value="">{{ __('events.divisions.none') }}</option>
                                        </select>
                                    @endif
                                </div>
                            @endforeach
                            @if ($edit && $u['people'] === [])
                                <button type="button" class="underline text-gray-500" wire:click="removeUnit({{ $u['model']->id }})">{{ __('events.divisions.remove_unit') }}</button>
                            @endif
                        </div>
                    @endforeach
                </div>
                @if ($d['open'] !== [])
                    <h4 class="mt-4 mb-1 font-semibold">{{ __('events.divisions.open') }} ({{ count($d['open']) }})</h4>
                    @foreach ($d['open'] as $p)
                        <div style="display:flex;flex-wrap:wrap;gap:.25rem .5rem;align-items:center;padding:.2rem 0;border-top:1px solid rgba(128,128,128,.15)">
                            <span style="flex:1 1 9rem;min-width:0;overflow-wrap:anywhere">{{ $p['name'] }} <span class="text-gray-500">{{ $short($p) }}{{ $p['role'] !== 'child' ? ' · '.__('events.roles.'.$p['role']) : '' }}</span></span>
                            @if ($edit)
                                <select wire:change="move({{ $d['model']->id }}, {{ $p['id'] }}, $event.target.value)" aria-label="{{ __('events.divisions.move') }}" style="{{ $input }}">
                                    <option value="">{{ __('events.divisions.none') }}</option>
                                    @foreach ($d['units'] as $o)<option value="{{ $o['model']->id }}">{{ $o['model']->name }}</option>@endforeach
                                </select>
                            @endif
                        </div>
                    @endforeach
                @endif
            </div>
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
