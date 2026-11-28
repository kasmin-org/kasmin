@php($money = fn (int $c) => \App\Support\Accounting\Money::format($c))
<div class="space-y-3 text-sm" data-entry>
    <p><strong>{{ __('accounting.number_short') }} {{ $entry->number }}</strong> · {{ $entry->date->isoFormat('L') }} · {{ $entry->text }}@if ($entry->receipt) · {{ __('accounting.receipt') }}: {{ $entry->receipt }}@endif</p>
    @if ($entry->reverses_id)<p class="text-gray-500">{{ __('accounting.is_reversal', ['number' => $entry->reverses?->number]) }}</p>@endif
    @if ($entry->reversal)<p class="text-gray-500">{{ __('accounting.was_reversed', ['number' => $entry->reversal->number]) }}</p>@endif
    <div class="overflow-x-auto"><table class="w-full">
        <thead><tr><th class="py-1" style="text-align:start">{{ __('accounting.account') }}</th><th class="py-1" style="text-align:start">{{ __('accounting.cost_center') }} / {{ __('accounting.fund') }}</th><th class="py-1" style="text-align:end">{{ __('accounting.debit') }}</th><th class="py-1" style="text-align:end">{{ __('accounting.credit') }}</th></tr></thead>
        <tbody>
        @foreach ($entry->lines as $line)
            <tr class="border-t border-gray-200 dark:border-white/10">
                <td class="py-1">{{ $line->account?->label() }}@if ($line->person) <span class="text-gray-500">· {{ $line->person->fullName() }}</span>@endif @if ($line->text)<br><span class="text-gray-500">{{ $line->text }}</span>@endif</td>
                <td class="py-1">{{ $line->costCenter?->name ?? $line->fund?->name ?? '' }}</td>
                <td class="py-1 text-end">{{ $line->debit_cents ? $money($line->debit_cents) : '' }}</td>
                <td class="py-1 text-end">{{ $line->credit_cents ? $money($line->credit_cents) : '' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
</div>
