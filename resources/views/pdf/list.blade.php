<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<title>{{ $list->title }}</title>
<style>
    @page { margin: 16mm 14mm 18mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 8.5pt; color: #222; }
    h1 { font-size: 14pt; margin: 0 0 1mm; }
    .sub { color: #555; margin-bottom: 4mm; }
    .secret { color: #8a1c1c; font-weight: bold; }
    table { width: 100%; border-collapse: collapse; }
    thead { display: table-header-group; }
    th { text-align: left; border-bottom: 1px solid #555; padding: 3px; }
    td { border-bottom: 1px solid #ddd; padding: 3px; vertical-align: top; white-space: pre-line; }
    tr { page-break-inside: avoid; }
    .changed { background: #fff2b3; font-weight: bold; }
    .footer { position: fixed; bottom: -10mm; left: 0; right: 0; font-size: 7pt; color: #777; }
</style>
</head>
<body>
<div class="footer">@if ($list->confidential){{ __('lists.confidential') }} · @endif{{ now()->isoFormat('LLL') }}</div>
<h1>{{ $list->title }}</h1>
<div class="sub">{{ $subtitle }}@if ($list->confidential) <span class="secret">· {{ __('lists.confidential') }}</span>@endif</div>
<table>
    <thead><tr>@foreach ($list->headers as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
    <tbody>
    @forelse ($list->rows as $r => $row)
        <tr>@foreach ($row as $c => $cell)<td @class(['changed' => $list->marked($r, $c)])>{{ $cell }}</td>@endforeach</tr>
    @empty
        <tr><td colspan="{{ max(1, count($list->headers)) }}">{{ __('lists.empty') }}</td></tr>
    @endforelse
    </tbody>
</table>
@if ($list->marks !== [])<p class="sub"><span class="changed">{{ __('lists.changed') }}</span></p>@endif
</body>
</html>
