<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<title>{{ __('lists.folder') }}</title>
<style>
    @page { margin: 16mm 16mm 18mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9.5pt; color: #222; }
    h1 { font-size: 16pt; margin: 0; }
    h2 { font-size: 11pt; margin: 5mm 0 1mm; border-bottom: 1px solid #999; }
    .secret { color: #8a1c1c; font-weight: bold; }
    table { width: 100%; border-collapse: collapse; }
    td { padding: 2px 4px; vertical-align: top; border-bottom: 1px solid #eee; white-space: pre-line; }
    td.l { width: 38%; color: #555; }
    .changed { background: #fff2b3; font-weight: bold; }
    .page { page-break-after: always; }
    .page:last-child { page-break-after: auto; }
    .footer { position: fixed; bottom: -10mm; left: 0; right: 0; font-size: 7pt; color: #777; }
</style>
</head>
<body>
<div class="footer">{{ __('lists.confidential') }} · {{ $event->title }} · {{ now()->isoFormat('LLL') }}</div>
@foreach ($sheets as $sheet)
<div class="page">
    <h1>{{ $sheet['person']->fullName() }}</h1>
    <div>{{ $sheet['person']->birth_date?->isoFormat('LL') ?? '–' }} · {{ __('admin.person.age') }} {{ $sheet['person']->ageAt($event->ageReferenceDate()) ?? '–' }} · {{ $event->title }} <span class="secret">· {{ __('lists.confidential') }}</span></div>
    <h2>{{ __('forms.emergency_contacts') }}</h2>
    <table>@foreach ($sheet['contacts'] as $c)<tr><td class="l">{{ $c['name'] }} ({{ $c['relation'] }})</td><td>{{ $c['phone'] }}</td></tr>@endforeach</table>
    @foreach ($sheet['forms'] as $f)
        <h2>{{ $f['title'] }} – {{ $f['status'] }}</h2>
        <table>@foreach ($f['fields'] as $row)<tr><td class="l">{{ $row['label'] }}</td><td @class(['changed' => $row['changed']])>{{ $row['value'] }}</td></tr>@endforeach</table>
    @endforeach
</div>
@endforeach
</body>
</html>
