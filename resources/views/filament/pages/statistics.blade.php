@php
    $day = fn (?string $d) => $d ? \Illuminate\Support\Carbon::parse($d)->isoFormat('ll') : '–';
    $money = fn (int $c) => \App\Models\Invoice::money($c);
    $grid = 'display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:1rem';
    $max = max([1, ...array_values($ages)]);
@endphp
<x-filament-panels::page>
    <div style="display:flex;flex-wrap:wrap;gap:1rem;align-items:end">
        <label class="text-sm">{{ __('reports.event') }}<br>
            <select wire:model.live="event" class="rounded-lg border-gray-300 dark:bg-white/5">
                @foreach ($events as $id => $title)<option value="{{ $id }}">{{ $title }}</option>@endforeach
            </select>
        </label>
        <label class="text-sm">{{ __('reports.role') }}<br>
            <select wire:model.live="role" class="rounded-lg border-gray-300 dark:bg-white/5">
                <option value="">{{ __('reports.all_roles') }}</option>
                @foreach ($roleOptions as $r)<option value="{{ $r }}">{{ __('events.roles.'.$r) }}</option>@endforeach
            </select>
        </label>
    </div>

    @if ($model)
        <div style="{{ $grid }}">
            <x-filament::section :heading="__('reports.ages')">
                @forelse ($ages as $age => $count)
                    <div style="display:flex;align-items:center;gap:.5rem" class="text-sm">
                        <span style="width:2rem;flex:none;text-align:right">{{ $age }}</span>
                        <span style="display:inline-block;height:.9rem;border-radius:4px;background:#1da029;width:{{ max(4, (int) round($count / $max * 80)) }}%" aria-hidden="true"></span>
                        <span>{{ $count }}</span>
                    </div>
                @empty
                    <p class="text-sm">–</p>
                @endforelse
            </x-filament::section>

            <x-filament::section :heading="__('reports.age_groups')">
                <label class="text-sm">{{ __('reports.group_count') }}
                    <select wire:model.live="groups" class="rounded-lg border-gray-300 dark:bg-white/5">@foreach ([2, 3, 4, 5] as $n)<option value="{{ $n }}">{{ $n }}</option>@endforeach</select>
                </label>
                @if ($mayExport && $ageGroups !== [])
                    {{-- Export of the members of the groups: choose the fields, then the format. --}}
                    <details class="kasmin-export text-sm" style="display:inline-block;position:relative;vertical-align:middle;margin-left:.5rem" data-age-group-export wire:key="export-{{ $model->id }}-{{ $groups }}-{{ $role }}">
                        <summary style="display:inline-flex;align-items:center;gap:.35rem;cursor:pointer;list-style:none;border:1px solid rgba(128,128,128,.45);border-radius:.5rem;padding:.35rem .7rem;font-weight:600">{{ __('reports.export') }}</summary>
                        <form method="get" action="{{ route('admin.reports.age-groups', ['event' => $model->id, 'format' => 'xlsx']) }}" target="_blank" class="rounded-lg bg-white dark:bg-gray-900" style="position:absolute;z-index:20;left:0;top:2.4rem;width:min(19rem,82vw);padding:.8rem;border:1px solid rgba(128,128,128,.45);box-shadow:0 6px 18px rgba(0,0,0,.18)">
                            <input type="hidden" name="groups" value="{{ $groups }}">
                            @if ($role !== '')<input type="hidden" name="role" value="{{ $role }}">@endif
                            <p class="font-medium" style="margin-bottom:.35rem">{{ __('reports.export_fields') }}</p>
                            @foreach ($exportFields as $field)
                                <label style="display:flex;align-items:center;gap:.5rem;padding:.2rem 0"><input type="checkbox" name="fields[]" value="{{ $field }}" checked> {{ __('reports.fields.'.$field) }}</label>
                            @endforeach
                            <p class="font-medium" style="margin:.6rem 0 .35rem">{{ __('reports.export_as') }}</p>
                            <div style="display:flex;flex-wrap:wrap;gap:.4rem">
                                @foreach (['xlsx' => 'Excel', 'csv' => 'CSV', 'pdf' => 'PDF'] as $format => $label)
                                    <button type="submit" formaction="{{ route('admin.reports.age-groups', ['event' => $model->id, 'format' => $format]) }}" data-export-format="{{ $format }}" style="border:1px solid #1da029;color:#14501c;background:#eaf7ec;border-radius:.5rem;padding:.4rem .8rem;font-weight:600">{{ $label }}</button>
                                @endforeach
                            </div>
                            <p class="text-gray-500" style="margin-top:.5rem;font-size:.75rem">{{ __('reports.export_note') }}</p>
                        </form>
                    </details>
                @endif
                @foreach ($ageGroups as $i => $g)
                    <details class="mt-2 text-sm">
                        <summary><strong>{{ __('reports.group') }} {{ $i + 1 }}</strong>: {{ $g['size'] }} · {{ __('reports.age') }} {{ $g['min_age'] }}–{{ $g['max_age'] }} · {{ __('reports.born') }} {{ $day($g['from']) }} – {{ $day($g['to']) }}</summary>
                        <p>{{ implode(', ', $g['names']) }}</p>
                    </details>
                @endforeach
            </x-filament::section>

            @if ($costs)
                <x-filament::section :heading="__('reports.costs')">
                    <table class="w-full text-sm">
                        <tr><td class="py-1 pe-4">{{ __('reports.income') }}</td><td class="py-1 ps-4" style="text-align:right">{{ $money($costs['income']) }}</td></tr>
                        <tr><td class="text-gray-500">{{ __('reports.of_which_open') }}</td><td style="text-align:right" class="text-gray-500">{{ $money($costs['open']) }}</td></tr>
                        <tr><td class="text-gray-500">{{ __('reports.discounts_given') }}</td><td style="text-align:right" class="text-gray-500">{{ $money($costs['discounts']) }}</td></tr>
                        @foreach ($costs['costs_by_category'] as $cat => $cents)
                            <tr><td class="py-1 pe-4">– {{ __('admin.finance.cost_categories.'.$cat) }}</td><td class="py-1 ps-4" style="text-align:right">{{ $money($cents) }}</td></tr>
                        @endforeach
                        <tr><td class="py-1 pe-4"><strong>{{ __('reports.result') }}</strong></td><td class="py-1 ps-4" style="text-align:right"><strong @if ($costs['result'] < 0) style="color:#b91c1c" @endif>{{ $money($costs['result']) }}</strong></td></tr>
                        <tr><td class="py-1 pe-4">{{ __('reports.cost_per_participant', ['count' => $costs['participants']]) }}</td><td class="py-1 ps-4" style="text-align:right">{{ $costs['per_participant'] !== null ? $money($costs['per_participant']) : '–' }}</td></tr>
                    </table>
                    @if ($costs['costs'] === 0)<p class="mt-2 text-xs text-gray-500">{{ __('reports.no_costs') }}</p>@endif
                </x-filament::section>
            @endif

            @if ($discounts)
                <x-filament::section :heading="__('reports.discounts')">
                    <ul class="text-sm">
                        @forelse ($discounts['sibling'] as $position => $d)
                            <li>{{ __('reports.sibling_position', ['position' => $position]) }}: {{ $d['count'] }} × ({{ $money($d['cents']) }})</li>
                        @empty
                            <li>{{ __('reports.no_sibling') }}</li>
                        @endforelse
                        <li>{{ __('reports.role_discounts') }}: {{ $discounts['role']['count'] }} × ({{ $money($discounts['role']['cents']) }})</li>
                    </ul>
                </x-filament::section>
            @endif
        </div>
    @endif

    <x-filament::section :heading="__('reports.years')">
        <div style="overflow-x:auto">
            <table class="w-full text-sm">
                <thead><tr style="text-align:left"><th class="py-1 pe-4">{{ __('reports.series') }}</th><th class="py-1 pe-4">{{ __('reports.event') }}</th><th class="py-1 ps-4" style="text-align:right">{{ __('reports.participants') }}</th><th class="py-1 ps-4" style="text-align:right">{{ __('reports.returning') }}
                    {{-- Explains the word on hover (and on focus or tap, as a phone has no hover). Not a link, not a button. --}}
                    <span class="kasmin-hint" tabindex="0" role="note" data-hint="returning" aria-label="{{ __('reports.returning_hint') }}">?<span class="kasmin-hint-text" aria-hidden="true">{{ __('reports.returning_hint') }}</span></span></th><th class="py-1 ps-4" style="text-align:right">{{ __('reports.new') }}</th></tr></thead>
                <tbody>
                @forelse ($years as $y)
                    <tr class="border-t border-gray-200 dark:border-white/10"><td class="py-1 pe-4">{{ $y['series'] }}</td><td class="py-1 pe-4">{{ $y['title'] }}</td><td class="py-1 ps-4" style="text-align:right">{{ $y['participants'] }}</td><td class="py-1 ps-4" style="text-align:right">{{ $y['returning'] }}</td><td class="py-1 ps-4" style="text-align:right">{{ $y['new'] }}</td></tr>
                @empty
                    <tr><td colspan="5">–</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
    <style>
        .kasmin-hint{position:relative;display:inline-flex;align-items:center;justify-content:center;width:1.15rem;height:1.15rem;border-radius:999px;border:1px solid currentColor;font-size:.75rem;font-weight:600;cursor:help;opacity:.75;vertical-align:middle;user-select:none}
        .kasmin-hint-text{display:none;position:absolute;right:0;top:1.5rem;z-index:20;width:min(20rem,75vw);padding:.6rem .75rem;border-radius:.5rem;background:#1f2937;color:#fff;font-weight:400;font-size:.8rem;line-height:1.35;text-align:left;white-space:normal;box-shadow:0 4px 12px rgba(0,0,0,.25)}
        .kasmin-hint:hover .kasmin-hint-text,.kasmin-hint:focus .kasmin-hint-text{display:block}
        .kasmin-hint:focus{outline:2px solid #1da029;outline-offset:2px}
    </style>
</x-filament-panels::page>
