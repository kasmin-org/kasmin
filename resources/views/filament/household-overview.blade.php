@php
    $record = $getRecord();
    $o = $record ? app(\App\Support\People\Overview::class)->household($record, auth()->user()) : null;
    $row = 'display:flex;flex-wrap:wrap;gap:0 1rem;padding:.25rem 0;border-bottom:1px solid rgba(0,0,0,.06)';
    $dt = 'font-weight:600;flex:0 0 11rem';
    $dd = 'flex:1 1 14rem;min-width:0;overflow-wrap:anywhere';
@endphp
@if ($o)
<div class="text-sm" data-household-overview>
    @if ($o['emergency'] !== null)
        <div style="{{ $row }}"><div style="{{ $dt }}">{{ __('forms.emergency_contacts') }}</div><div style="{{ $dd }}">
            @forelse ($o['emergency'] as $c)
                <div>{{ $c['name'] }}@if ($c['relation']) ({{ $c['relation'] }})@endif · {{ $c['phone'] }}@if ($c['note']) · {{ $c['note'] }}@endif</div>
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
    @if ($o['mails'] !== null)
        <div style="{{ $row }}"><div style="{{ $dt }}">{{ __('admin.overview.mails') }}</div><div style="{{ $dd }}">
            @forelse ($o['mails'] as $m)
                <div><a class="underline" href="{{ $m['url'] }}">{{ $m['subject'] }}</a> · {{ $m['to'] }} · {{ __('admin.mail.statuses.'.$m['status']) }} · {{ $m['at']?->isoFormat('L') }}</div>
            @empty – @endforelse
        </div></div>
    @endif
</div>
@endif
