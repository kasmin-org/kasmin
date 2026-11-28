<x-filament-panels::page>
    <div class="grid gap-4 md:grid-cols-2" data-money-accounts>
        @forelse ($rows as $row)
            <x-filament::section :heading="$row['account']->label()" :description="__('accounting.money_kinds.'.$row['account']->money).($row['account']->iban ? ' · '.$row['account']->iban : '')">
                <p class="text-2xl font-semibold">{{ $row['balance'] }}</p>
                <label class="mt-3 grid gap-1 text-sm">{{ __('accounting.statement_balance') }}
                    <input type="text" inputmode="decimal" wire:model.live.debounce.400ms="statement.{{ $row['account']->id }}" placeholder="0,00" style="border:1px solid rgba(128,128,128,.45);border-radius:.5rem;padding:.4rem .6rem;background:transparent;max-width:12rem"></label>
                @if ($row['difference'] !== null)
                    <p class="mt-1 text-sm" style="color:{{ $row['matches'] ? '#15803d' : '#b91c1c' }}">{{ $row['matches'] ? __('accounting.statement_matches') : __('accounting.statement_difference', ['amount' => $row['difference']]) }}</p>
                @endif
                @if ($row['funds'] !== [])
                    <p class="mt-3 text-sm font-semibold">{{ __('accounting.funds.on_account') }}</p>
                    <ul class="text-sm">@foreach ($row['funds'] as $fund)<li>{{ $fund['name'] }}: {{ ($row['money'])($fund['balance']) }}</li>@endforeach</ul>
                    <p class="text-sm text-gray-500">{{ __('accounting.funds.difference', ['amount' => $row['funds_difference']]) }}</p>
                @endif
                <p class="mt-3 text-sm"><a class="underline" href="{{ \App\Filament\Pages\AccountingReportsPage::getUrl(['report' => 'ledger', 'account' => $row['account']->id]) }}">{{ __('accounting.ledger') }}</a></p>
            </x-filament::section>
        @empty
            <p class="text-sm text-gray-500">{{ __('accounting.no_money_accounts') }}</p>
        @endforelse
    </div>
</x-filament-panels::page>
