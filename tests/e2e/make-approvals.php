<?php

use App\Models\Automation;
use App\Models\Event;
use App\Models\OutgoingMail;
use App\Support\Automations\Engine;
use App\Support\Mail\MailLinks;

// Used by the CI GUI job (php artisan tinker): creates two pending approval requests (copies of the standard
// automation "event information" for the demo camp, so the demo data stays untouched) and prints the
// administrator's approval link paths, one per line.
// Single requests at once, so the links exist now (the default is one summary per day).
\App\Support\Settings::set('approval_summary_time', '');
$event = Event::query()->whereHas('installments')->orderBy('starts_at')->firstOrFail();
$template = Automation::query()->where('key', 'event_information')->firstOrFail();
$links = [];
foreach (['gui_desktop', 'gui_phone'] as $key) {
    $automation = $template->replicate(['last_run_at']);
    $automation->fill(['key' => $key.'_'.uniqid(), 'active' => true, 'mode' => 'confirm', 'is_standard' => false])->save();
    app(Engine::class)->trigger($automation, 'gui-test', $event);
    // The personal link is only made when the mail is sent.
    $mail = OutgoingMail::query()->where('to_address', 'admin@example.org')->latest('id')->firstOrFail();
    preg_match('#/approvals/[A-Za-z0-9]{48}#', MailLinks::resolve($mail)['html'], $m);
    $links[] = $m[0] ?? '';
}
echo implode("\n", $links)."\n";
