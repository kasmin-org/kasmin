@php($colours = ['done' => '#15803d', 'failed' => '#b91c1c', 'denied' => '#b91c1c', 'pending_approval' => '#b45309', 'planned' => '#15803d', 'off' => '#6b7280', 'manual' => '#6b7280'])
<x-filament-panels::page>
    <x-filament::section>
        <ol class="text-sm">
            @foreach ($items as $item)
                @php($days = (int) $event->starts_at->copy()->startOfDay()->diffInDays($item['at']->copy()->startOfDay(), false))
                <li style="display:flex;flex-wrap:wrap;gap:.25rem 1rem;padding:.45rem 0;border-bottom:1px solid #e5e7eb;{{ $item['at']->isPast() ? 'opacity:.7' : '' }}">
                    <span style="min-width:9.5rem">{{ $item['at']->isoFormat('dd L') }}</span>
                    <span style="min-width:6rem">{{ $days === 0 ? __('automations.schedule.day') : ($days < 0 ? __('automations.schedule.before', ['days' => -$days]) : __('automations.schedule.after', ['days' => $days])) }}</span>
                    <span style="flex:1 1 14rem;overflow-wrap:anywhere;{{ $item['kind'] === 'date' ? 'font-weight:600' : '' }}">
                        @if ($links && $item['run'])<a class="underline" href="{{ \App\Filament\Resources\AutomationRuns\AutomationRunResource::getUrl('view', ['record' => $item['run']]) }}">{{ $item['what'] }}</a>
                        @elseif ($links && $item['automation'])<a class="underline" href="{{ \App\Filament\Resources\Automations\AutomationResource::getUrl('edit', ['record' => $item['automation']]) }}">{{ $item['what'] }}</a>
                        @else{{ $item['what'] }}@endif
                    </span>
                    @if ($item['state'] !== '')<span style="color:{{ $colours[$item['state']] ?? '#6b7280' }}">{{ $item['kind'] === 'run' ? __('automations.statuses.'.$item['state']) : __('automations.schedule.'.$item['state']) }}</span>@endif
                </li>
            @endforeach
        </ol>
    </x-filament::section>
</x-filament-panels::page>
