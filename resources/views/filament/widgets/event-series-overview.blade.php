@php
    $money = fn (int $c) => \App\Models\Invoice::money($c);
    $grid = 'display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));gap:1rem';
@endphp
<x-filament-widgets::widget>
    @if ($blocks === [])
        <x-filament::section>{{ __('dashboard.no_events') }}</x-filament::section>
    @else
        <div style="{{ $grid }}">
            @foreach ($blocks as $b)
                <x-filament::section>
                    <x-slot name="heading">
                        <a href="{{ \App\Filament\Resources\Events\EventResource::getUrl('edit', ['record' => $b['id']]) }}">{{ $b['title'] }}</a>
                    </x-slot>
                    <x-slot name="description">
                        {{ $b['series'] ?? __('dashboard.no_series') }} ·
                        @if ($b['days'] > 0) {{ trans_choice('dashboard.in_days', $b['days'], ['days' => $b['days']]) }}
                        @elseif ($b['days'] === 0) {{ __('dashboard.today') }}
                        @else {{ $b['starts_at']->isoFormat('LL') }} @endif
                    </x-slot>
                    <dl style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.5rem 1rem" class="text-sm">
                        <div><dt class="text-gray-500">{{ __('dashboard.registrations') }}</dt><dd class="text-xl font-semibold">{{ $b['registrations'] }}</dd></div>
                        <div><dt class="text-gray-500">{{ __('dashboard.waitlist') }}</dt><dd class="text-xl font-semibold">{{ $b['waitlist'] }}</dd></div>
                        <div><dt class="text-gray-500">{{ __('dashboard.open_places') }}</dt><dd class="text-xl font-semibold">{{ $b['open_places'] ?? '∞' }}</dd></div>
                        <div><dt class="text-gray-500">{{ __('dashboard.reserve_left') }}</dt><dd class="text-xl font-semibold">{{ $b['reserve_left'] }}</dd></div>
                    </dl>
                    @if (count($b['roles']) > 1)
                        <p class="mt-2 text-xs text-gray-500">
                            @foreach ($b['roles'] as $r){{ __('events.roles.'.$r['role']) }}: {{ $r['occupied'] }}{{ $r['capacity'] !== null ? '/'.$r['capacity'] : '' }}{{ $r['waitlist'] ? ' (+'.$r['waitlist'].')' : '' }}@if (! $loop->last) · @endif @endforeach
                        </p>
                    @endif
                    <h4 class="mt-3 text-sm font-semibold">{{ __('dashboard.latest') }}</h4>
                    <ul class="text-sm">
                        @forelse ($b['latest'] as $l)
                            <li>{{ $l['name'] }} <span class="text-gray-500">· {{ __('events.roles.'.$l['role']) }} · {{ __('events.status.'.$l['status']) }} · {{ $l['at']->diffForHumans() }}</span></li>
                        @empty
                            <li class="text-gray-500">–</li>
                        @endforelse
                    </ul>
                    @isset($b['finance'])
                        <h4 class="mt-3 text-sm font-semibold">{{ __('dashboard.invoices') }}</h4>
                        <p class="text-sm">
                            <span style="color:#15803d">{{ __('dashboard.paid') }} {{ $b['finance']['paid']['count'] }} ({{ $money($b['finance']['paid']['cents']) }})</span> ·
                            {{ __('dashboard.open') }} {{ $b['finance']['open']['count'] }} ({{ $money($b['finance']['open']['cents']) }}) ·
                            <span style="color:#b91c1c">{{ __('dashboard.overdue') }} {{ $b['finance']['overdue']['count'] }} ({{ $money($b['finance']['overdue']['cents']) }})</span>
                        </p>
                    @endisset
                </x-filament::section>
            @endforeach
        </div>
    @endif
</x-filament-widgets::widget>
