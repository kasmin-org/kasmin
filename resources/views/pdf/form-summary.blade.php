<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<title>{{ $form->label() }}</title>
<style>
    @page { margin: 16mm 16mm 18mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9.5pt; color: #222; }
    h1 { font-size: 16pt; margin: 0; }
    h2 { font-size: 11pt; margin: 5mm 0 1mm; border-bottom: 1px solid #999; }
    table { width: 100%; border-collapse: collapse; }
    td { padding: 2px 4px; vertical-align: top; border-bottom: 1px solid #eee; white-space: pre-line; }
    td.l { width: 38%; color: #555; }
    .footer { position: fixed; bottom: -10mm; left: 0; right: 0; font-size: 7pt; color: #777; }
</style>
</head>
<body>
<div class="footer">{{ __('lists.confidential') }} · {{ __('forms.summary_without_numbers') }}</div>
<h1>{{ $form->label() }}</h1>
<div>{{ $person->fullName() }} · {{ __('forms.confirmed_on', ['date' => $response->confirmed_at?->isoFormat('LL') ?? '–']) }}</div>
<h2>{{ __('forms.emergency_contacts') }}</h2>
<table>@foreach ($contacts as $c)<tr><td class="l">{{ $c['name'] }} ({{ $c['relation'] }})</td><td>{{ $c['phone'] }}</td></tr>@endforeach</table>
<h2>{{ $form->label() }}</h2>
<table>@foreach ($rows as $row)<tr><td class="l">{{ $row['label'] }}</td><td>{{ $row['value'] }}</td></tr>@endforeach</table>
</body>
</html>
