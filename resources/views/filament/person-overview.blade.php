@php
    $o = app(\App\Support\People\Overview::class)->person($getRecord(), auth()->user());
    $links = fn (array $items) => collect($items)->map(fn ($i) => '<a class="underline" href="'.e($i['url']).'">'.e($i['name']).'</a>')->implode(', ');
    $row = 'display:flex;flex-wrap:wrap;gap:0 1rem;padding:.25rem 0;border-bottom:1px solid rgba(0,0,0,.06)';
    $dt = 'font-weight:600;flex:0 0 11rem';
    $dd = 'flex:1 1 14rem;min-width:0;overflow-wrap:anywhere';
@endphp
<div class="text-sm" data-person-overview>
    <div style="{{ $row }}"><div style="{{ $dt }}">{{ __('admin.household.guardians') }}</div><div style="{{ $dd }}">{!! $links($o['guardians']) ?: '–' !!}</div></div>
    <div style="{{ $row }}"><div style="{{ $dt }}">{{ __('admin.household.children') }}</div><div style="{{ $dd }}">{!! $links($o['children']) ?: '–' !!}</div></div>
    <div style="{{ $row }}"><div style="{{ $dt }}">{{ __('admin.household.plural') }}</div><div style="{{ $dd }}">{!! $links($o['families']) ?: '–' !!}</div></div>
    <div style="{{ $row }}"><div style="{{ $dt }}">{{ __('admin.overview.roles') }}</div><div style="{{ $dd }}">{{ collect($o['roles'])->map(fn ($r) => \App\Models\Role::query()->where('key', $r)->first()?->label() ?? $r)->implode(', ') ?: '–' }}</div></div>
    <div style="{{ $row }}"><div style="{{ $dt }}">{{ __('admin.group.plural') }}</div><div style="{{ $dd }}">{{ implode(', ', $o['groups']) ?: '–' }}</div></div>
    <div style="{{ $row }}"><div style="{{ $dt }}">{{ __('admin.overview.memberships') }}</div><div style="{{ $dd }}">{{ implode(', ', $o['memberships']) ?: '–' }}</div></div>
    @if ($o['events'] !== null)
        <div style="{{ $row }}"><div style="{{ $dt }}">{{ __('admin.event.plural') }}</div><div style="{{ $dd }}">
            @forelse ($o['events'] as $e)
                <div>@if ($e['url'])<a class="underline" href="{{ $e['url'] }}">{{ $e['title'] }}</a>@else{{ $e['title'] }}@endif · {{ __('events.roles.'.$e['role']) }} · {{ __('events.status.'.$e['status']) }}</div>
            @empty – @endforelse
        </div></div>
    @endif
    @if ($o['invoices'] !== null)
        <div style="{{ $row }}"><div style="{{ $dt }}">{{ __('finance.invoices') }}</div><div style="{{ $dd }}">
            @forelse ($o['invoices'] as $i)
                <div><a class="underline" href="{{ $i['url'] }}">{{ $i['number'] }}</a> · {{ $i['total'] }} · {{ __('finance.status.'.$i['status']) }}</div>
            @empty – @endforelse
        </div></div>
    @endif
    @if ($o['consents'] !== null)
        <div style="{{ $row }}"><div style="{{ $dt }}">{{ __('privacy.consents') }}</div><div style="{{ $dd }}">
            @forelse ($o['consents'] as $c)
                <div>{{ $c['kind'] }}: {{ $c['granted'] ? __('admin.overview.yes') : __('admin.overview.no') }} · {{ $c['at']?->isoFormat('L') }}</div>
            @empty – @endforelse
        </div></div>
    @endif
    <div style="{{ $row }}"><div style="{{ $dt }}">{{ __('admin.overview.login') }}</div><div style="{{ $dd }}">
        @if ($o['login']['address'])
            {{ $o['login']['address'] }} · {{ __('admin.overview.last_login') }}: {{ $o['login']['last']?->isoFormat('LLL') ?? __('admin.overview.never') }} · {{ __('admin.overview.second_factor') }}: {{ $o['login']['second_factor'] ? __('admin.overview.yes') : __('admin.overview.no') }}
        @else
            {{ __('admin.overview.no_login') }}
        @endif
    </div></div>
</div>
