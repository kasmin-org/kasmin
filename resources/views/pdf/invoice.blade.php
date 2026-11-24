@php
    $money = fn (int $c) => \App\Models\Invoice::money($c, $invoice->currency);
    $date = fn ($d) => $d ? $d->locale($locale)->translatedFormat($locale === 'de' ? 'd.m.Y' : 'j F Y') : '–';
    $title = $invoice->type === 'credit_note' ? __('finance.credit_note') : __('finance.invoice');
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8">
<title>{{ $title }} {{ $invoice->number }}</title>
<style>
    @page { margin: 22mm 20mm 28mm 20mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #222; }
    .org { text-align: right; font-size: 9pt; color: #555; white-space: pre-line; }
    .org strong { font-size: 13pt; color: #1da029; }
    .address { margin-top: 18mm; white-space: pre-line; min-height: 30mm; }
    h1 { font-size: 16pt; margin: 8mm 0 2mm; }
    table.meta td { padding: 1px 10px 1px 0; }
    table.lines { width: 100%; border-collapse: collapse; margin-top: 8mm; }
    table.lines th { text-align: left; border-bottom: 1px solid #999; padding: 4px 2px; }
    table.lines td { padding: 4px 2px; border-bottom: 1px solid #eee; vertical-align: top; }
    .r { text-align: right; white-space: nowrap; }
    .muted { color: #777; font-size: 8.5pt; }
    tr.total td { font-weight: bold; border-top: 1px solid #222; border-bottom: none; }
    .pay { margin-top: 10mm; }
    .pay td { vertical-align: top; }
    .stamp { margin-top: 10mm; display: inline-block; border: 3px solid #1da029; color: #1da029; font-size: 20pt; font-weight: bold; padding: 4px 16px; transform: rotate(-6deg); }
    .footer { position: fixed; bottom: -18mm; left: 0; right: 0; font-size: 7.5pt; color: #777; text-align: center; white-space: pre-line; }
</style>
</head>
<body>
<div class="footer">{{ $org['footer'] }}</div>
<div class="org"><strong>{{ $org['name'] }}</strong>
{{ $org['address'] }}
{{ $org['email'] }}{{ $org['website'] ? ' · '.$org['website'] : '' }}</div>

<div class="address">{{ $invoice->payer_name }}
{{ $invoice->payer_address }}</div>

<h1>{{ $title }} {{ $invoice->number ?? __('finance.status.draft') }}</h1>
<table class="meta">
    <tr><td>{{ __('finance.issued_on') }}</td><td>{{ $date($invoice->issued_on) }}</td></tr>
    @if ($invoice->type === 'invoice')
        <tr><td>{{ __('finance.due_on') }}</td><td>{{ $date($invoice->due_on) }}</td></tr>
    @endif
    @if ($invoice->event)
        <tr><td>{{ __('finance.event') }}</td><td>{{ $invoice->event->title }}</td></tr>
    @endif
    @if ($invoice->reference)
        <tr><td>{{ __('finance.reference') }}</td><td>{{ $invoice->reference }}</td></tr>
    @endif
</table>

<table class="lines">
    <thead><tr><th>{{ __('finance.description') }}</th><th class="r">{{ __('finance.discount') }}</th><th class="r">{{ __('finance.amount') }}</th></tr></thead>
    <tbody>
    @foreach ($invoice->lines as $line)
        <tr>
            <td>{{ $line->description }}</td>
            <td class="r">{{ $line->discount_cents > 0 ? '−'.$money($line->discount_cents) : '' }}</td>
            <td class="r">{{ $money($line->amount_cents) }}</td>
        </tr>
    @endforeach
    <tr class="total"><td>{{ __('finance.total') }}</td><td></td><td class="r">{{ $money($invoice->total_cents) }}</td></tr>
    @if ($invoice->paid_cents > 0 && ! $paid)
        <tr><td>{{ __('finance.already_paid') }}</td><td></td><td class="r">−{{ $money($invoice->paid_cents) }}</td></tr>
        <tr class="total"><td>{{ __('finance.open') }}</td><td></td><td class="r">{{ $money($invoice->openCents()) }}</td></tr>
    @endif
    </tbody>
</table>

@if ($paid)
    <div class="stamp">{{ mb_strtoupper(__('finance.paid')) }}@php($last = $invoice->payments()->max('paid_on'))@if ($last) {{ $date(\Illuminate\Support\Carbon::parse($last)) }}@endif</div>
    <p>{{ __('finance.pdf_paid_text') }}</p>
@elseif ($invoice->type === 'invoice' && $invoice->openCents() > 0)
    <table class="pay"><tr>
        <td>
            <p>{{ __('finance.pdf_pay_text', ['amount' => $money($invoice->openCents()), 'date' => $date($invoice->due_on)]) }}</p>
            @if ($org['iban'])
                <table class="meta">
                    <tr><td>{{ __('finance.recipient') }}</td><td>{{ $org['name'] }}</td></tr>
                    <tr><td>IBAN</td><td>{{ trim(chunk_split($org['iban'], 4, ' ')) }}</td></tr>
                    @if ($org['bic'])<tr><td>BIC</td><td>{{ $org['bic'] }}</td></tr>@endif
                    <tr><td>{{ __('finance.reference') }}</td><td><strong>{{ $invoice->reference }}</strong></td></tr>
                </table>
            @endif
        </td>
        @if ($qr)
            <td class="r" style="width: 38mm;"><img src="{{ $qr }}" style="width: 34mm;"><br><span class="muted">{{ __('finance.qr_hint') }}</span></td>
        @endif
    </tr></table>
@elseif ($invoice->type === 'credit_note')
    <p>{{ __('finance.pdf_credit_text') }}</p>
@endif
</body>
</html>
