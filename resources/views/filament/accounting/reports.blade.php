<x-filament-panels::page>
    @php($field = 'border:1px solid rgba(128,128,128,.45);border-radius:.5rem;padding:.4rem .6rem;background:transparent')
    <div class="flex flex-wrap items-end gap-3 text-sm" data-reports>
        <label class="grid gap-1">{{ __('accounting.reports.report') }}
            <select wire:model.live="report" style="{{ $field }}">@foreach ($this->reports() as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
        @if (in_array($report, ['income', 'cost_centers', 'funds'], true))
            <label class="grid gap-1">{{ __('accounting.period') }}
                <select wire:model.live="period" style="{{ $field }}">@foreach ($periods as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
        @endif
        @if ($report === 'income' && $centers !== [])
            <label class="grid gap-1">{{ __('accounting.cost_center') }}
                <select wire:model.live="costCenter" style="{{ $field }}"><option value="">{{ __('accounting.reports.all') }}</option>@foreach ($centers as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
        @endif
        @if (in_array($report, ['balance', 'open_items'], true))
            <label class="grid gap-1">{{ __('accounting.reports.on_day') }}<input type="date" wire:model.live="day" style="{{ $field }}"></label>
        @endif
        @if ($report === 'ledger')
            <label class="grid gap-1">{{ __('accounting.account') }}
                <select wire:model.live="account" style="{{ $field }}"><option value="">–</option>@foreach ($accounts as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
            <label class="grid gap-1">{{ __('accounting.periods.from') }}<input type="date" wire:model.live="from" style="{{ $field }}"></label>
            <label class="grid gap-1">{{ __('accounting.periods.to') }}<input type="date" wire:model.live="to" style="{{ $field }}"></label>
        @endif
        @if ($table)
            <div class="flex gap-2">
                @foreach (['xlsx' => 'Excel', 'csv' => 'CSV', 'pdf' => 'PDF'] as $format => $label)
                    <x-filament::button color="gray" size="sm" wire:click="export('{{ $format }}')" icon="heroicon-o-arrow-down-tray">{{ $label }}</x-filament::button>
                @endforeach
            </div>
        @endif
    </div>
    @if ($table === null)
        <p class="text-sm text-gray-500">{{ __('accounting.reports.choose') }}</p>
    @else
        <x-filament::section :heading="$table->title">
            <div class="overflow-x-auto"><table class="w-full text-sm" data-report-table>
                <thead><tr>@foreach ($table->headers as $i => $header)<th class="py-1" style="text-align:{{ $i === 0 ? 'start' : 'end' }}">{{ $header }}</th>@endforeach</tr></thead>
                <tbody>
                @forelse ($table->rows as $row)
                    <tr class="border-t border-gray-200 dark:border-white/10">@foreach ($row as $i => $cell)<td class="py-1 {{ $i === 0 ? '' : 'text-end' }} {{ str_starts_with((string) $cell, '  ') ? 'ps-4' : (count($row) === 2 && $row[1] === '' ? 'font-semibold' : '') }}" style="white-space:pre-wrap">{{ trim((string) $cell) }}</td>@endforeach</tr>
                @empty
                    <tr><td class="py-2 text-gray-500" colspan="{{ count($table->headers) }}">{{ __('accounting.reports.empty') }}</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
