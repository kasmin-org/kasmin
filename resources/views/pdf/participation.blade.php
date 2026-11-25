@php
    $date = fn ($d) => $d ? $d->locale($locale)->translatedFormat($locale === 'de' ? 'd.m.Y' : 'j F Y') : '–';
    $person = $participation->person;
    $event = $participation->event;
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8">
<title>{{ __('events.confirmation.title') }}</title>
<style>
    @page { margin: 22mm 20mm 28mm 20mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11pt; color: #222; line-height: 1.5; }
    .org { text-align: right; font-size: 9pt; color: #555; white-space: pre-line; line-height: 1.3; }
    .org strong { font-size: 13pt; color: #1da029; }
    h1 { font-size: 16pt; margin: 22mm 0 8mm; }
    table.meta td { padding: 2px 14px 2px 0; vertical-align: top; }
    .sign { margin-top: 22mm; }
    .muted { color: #777; font-size: 8.5pt; }
    .footer { position: fixed; bottom: -18mm; left: 0; right: 0; font-size: 7.5pt; color: #777; text-align: center; white-space: pre-line; }
</style>
</head>
<body>
<div class="footer">{{ $org['footer'] }}</div>
<div class="org"><strong>{{ $org['name'] }}</strong>
{{ $org['address'] }}
{{ $org['email'] }}{{ $org['website'] ? ' · '.$org['website'] : '' }}</div>

<h1>{{ __('events.confirmation.title') }}</h1>
<p>{{ __('events.confirmation.text', ['organisation' => $org['name']]) }}</p>
<table class="meta">
    <tr><td>{{ __('events.confirmation.name') }}</td><td><strong>{{ $person->fullName() }}</strong></td></tr>
    @if ($person->birth_date)<tr><td>{{ __('admin.person.birth_date') }}</td><td>{{ $date($person->birth_date) }}</td></tr>@endif
    <tr><td>{{ __('events.confirmation.event') }}</td><td><strong>{{ $event->title }}</strong></td></tr>
    <tr><td>{{ __('events.confirmation.period') }}</td><td>{{ $date($event->starts_at) }} – {{ $date($event->ends_at) }}</td></tr>
    <tr><td>{{ __('events.confirmation.role') }}</td><td>{{ __('events.roles.'.$participation->role_key) }}</td></tr>
    @if ($paid > 0)<tr><td>{{ __('events.confirmation.paid') }}</td><td>{{ \App\Models\Invoice::money($paid, $currency) }}</td></tr>@endif
</table>
<p class="sign">{{ $date(now()) }}<br>{{ $org['name'] }}</p>
<p class="muted">{{ __('events.confirmation.note') }}</p>
</body>
</html>
