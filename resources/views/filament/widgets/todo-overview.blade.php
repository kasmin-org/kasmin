@php
    $money = fn (int $c) => \App\Models\Invoice::money($c);
    $grid = 'display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,260px),1fr));gap:1rem';
    $links = [
        'approvals' => \App\Filament\Resources\AutomationRuns\AutomationRunResource::getUrl('index'),
        'requests' => \App\Filament\Resources\Events\EventResource::getUrl('index'),
        'new_registrations' => \App\Filament\Resources\Events\EventResource::getUrl('index'),
        'deletion_requests' => \App\Filament\Resources\People\PersonResource::getUrl('index'),
        'bounced' => \App\Filament\Resources\OutgoingMails\OutgoingMailResource::getUrl('index'),
        'unmatched_payments' => \App\Filament\Resources\Payments\PaymentResource::getUrl('index'),
    ];
@endphp
<x-filament-widgets::widget>
    <div style="{{ $grid }}">
        <x-filament::section :heading="__('dashboard.todos')">
            <ul class="text-sm">
                @foreach ($todos as $key => $count)
                    @continue($key === 'unmatched_payments' && $finance === null)
                    <li style="display:flex;justify-content:space-between;gap:.5rem;padding:.15rem 0">
                        <a href="{{ $links[$key] }}" class="underline">{{ __('dashboard.todo.'.$key) }}</a>
                        <span class="font-semibold" @if ($count > 0 && $key !== 'new_registrations') style="color:#b45309" @endif>{{ $count }}</span>
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
        @if ($finance !== null)
            <x-filament::section :heading="__('dashboard.finance')">
                <ul class="text-sm">
                    <li>{{ __('dashboard.open') }}: {{ $finance['open']['count'] }} ({{ $money($finance['open']['cents']) }})</li>
                    <li style="color:#b91c1c">{{ __('dashboard.overdue') }}: {{ $finance['overdue']['count'] }} ({{ $money($finance['overdue']['cents']) }})</li>
                    <li>{{ __('dashboard.todo.unmatched_payments') }}: {{ $finance['unmatched'] }}</li>
                    <li>{{ __('dashboard.last_import') }}: {{ $finance['last_import'] ? \Illuminate\Support\Carbon::parse($finance['last_import'])->diffForHumans() : '–' }}</li>
                </ul>
            </x-filament::section>
        @endif
        <x-filament::section :heading="__('dashboard.safety')">
            <ul class="text-sm">
                @forelse ($safety as $s)
                    <li>{{ $s['event'] }}: <span style="color:#15803d">{{ $s['confirmed'] }} {{ __('dashboard.confirmed') }}</span>, <span @if ($s['missing'] > 0) style="color:#b45309" @endif>{{ $s['missing'] }} {{ __('dashboard.missing') }}</span></li>
                @empty
                    <li class="text-gray-500">–</li>
                @endforelse
            </ul>
        </x-filament::section>
        <x-filament::section :heading="__('dashboard.automations')">
            <ul class="text-sm">
                @forelse ($automations['upcoming'] as $u)
                    <li>{{ $u['at']->isoFormat('dd L LT') }} – {{ $u['name'] }}@if ($u['event']) ({{ $u['event'] }})@endif</li>
                @empty
                    <li class="text-gray-500">{{ __('dashboard.no_automations') }}</li>
                @endforelse
                @if ($automations['failed'] > 0)
                    <li style="color:#b91c1c">{{ trans_choice('dashboard.failed_runs', $automations['failed'], ['count' => $automations['failed']]) }}</li>
                @endif
            </ul>
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
