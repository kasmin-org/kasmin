<?php

use App\Models\Automation;
use App\Models\Event;
use App\Models\Invoice;
use App\Models\OutgoingMail;
use App\Support\Automations\Engine;

// Used by the CI GUI job (php artisan tinker): creates two pending approval requests for the demo camp (deposit
// invoices and event information) and prints the administrator's approval link paths, one per line.
$event = Event::query()->whereHas('installments')->orderBy('starts_at')->firstOrFail();
Invoice::query()->where('event_id', $event->id)->delete(); // demo data already invoiced the deposit
$links = [];
foreach (['deposit_invoices', 'event_information'] as $key) {
    $automation = Automation::query()->where('key', $key)->firstOrFail();
    $automation->update(['active' => true, 'mode' => 'confirm']);
    app(Engine::class)->trigger($automation, 'gui-test', $event, 'gui-'.uniqid());
    $html = OutgoingMail::query()->where('to_address', 'admin@example.org')->latest('id')->value('html');
    preg_match('#/approvals/[A-Za-z0-9]+#', (string) $html, $m);
    $links[] = $m[0] ?? '';
}
echo implode("\n", $links)."\n";
