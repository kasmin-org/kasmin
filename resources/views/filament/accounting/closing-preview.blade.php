@php($money = fn (int $c) => \App\Support\Accounting\Money::format($c))
<div class="space-y-4 text-sm" data-closing-preview>
    @foreach ($preview['errors'] as $error)
        <p class="rounded-lg p-3" style="background:#fde2e2;color:#7f1d1d">{{ $error }}</p>
    @endforeach
    @foreach ($preview['warnings'] as $warning)
        <p class="rounded-lg p-3" style="background:#fdf0d5;color:#7a4b00">{{ $warning }}</p>
    @endforeach
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead><tr class="text-start">
                <th class="py-1 text-start">{{ __('accounting.cost_center') }}</th>
                <th class="py-1 text-end">{{ __('accounting.closing.before') }}</th>
                <th class="py-1 text-end">{{ __('accounting.closing.allocation') }}</th>
                <th class="py-1 text-end">{{ __('accounting.closing.after') }}</th>
            </tr></thead>
            <tbody>
            @forelse ($preview['rows'] as $row)
                <tr class="border-t border-gray-200 dark:border-white/10">
                    <td class="py-1">{{ $row['cost_center']->name }}@unless ($row['cost_center']->carries) <span class="text-gray-500">({{ __('accounting.cost_centers.allocated') }})</span>@endunless</td>
                    <td class="py-1 text-end">{{ $money($row['before']) }}</td>
                    <td class="py-1 text-end">{{ $row['allocation'] === 0 ? '' : $money($row['allocation']) }}</td>
                    <td class="py-1 text-end font-semibold">{{ $money($row['after']) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="py-2 text-gray-500">{{ __('accounting.closing.nothing') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if ($preview['funds'] !== [])
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead><tr>
                    <th class="py-1 text-start">{{ __('accounting.fund') }}</th>
                    <th class="py-1 text-end">{{ __('accounting.closing.fund_before') }}</th>
                    <th class="py-1 text-end">{{ __('accounting.closing.result') }}</th>
                    <th class="py-1 text-end">{{ __('accounting.closing.fund_after') }}</th>
                </tr></thead>
                <tbody>
                @foreach ($preview['funds'] as $row)
                    <tr class="border-t border-gray-200 dark:border-white/10" @if ($row['after'] < 0) style="color:#b91c1c" @endif>
                        <td class="py-1">{{ $row['fund']->name }}</td>
                        <td class="py-1 text-end">{{ $money($row['before']) }}</td>
                        <td class="py-1 text-end">{{ $money($row['result']) }}</td>
                        <td class="py-1 text-end font-semibold">{{ $money($row['after']) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
    <p class="text-gray-500">{{ __('accounting.closing.explain') }}</p>
</div>
