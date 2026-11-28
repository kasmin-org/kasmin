<?php

use App\Models\Event;
use App\Models\PublicForm;

// Used by the CI job "dast" (php artisan tinker, see scan.sh): the demo data has no public pages, so one event gets a
// public page and public forms. Prints the paths the scanner starts from, one per line.
$event = Event::query()->where('status', 'open')->orderBy('starts_at')->firstOrFail();
$event->forceFill(['public_page' => true])->save();
$role = $event->roles()->where('registered_by', '!=', 'admin')->orderBy('id')->first();
$forms = [
    ['slug' => 'zap-interest', 'kind' => 'interest', 'title' => ['en' => 'Stay informed', 'de' => 'Informiert bleiben']],
    ['slug' => 'zap-team', 'kind' => 'application', 'title' => ['en' => 'Join the team', 'de' => 'Im Team mitarbeiten']],
];
if ($role !== null) {
    $forms[] = ['slug' => 'zap-event', 'kind' => 'event', 'title' => ['en' => 'Registration', 'de' => 'Anmeldung'], 'event_id' => $event->id, 'role_key' => $role->role_key];
}
PublicForm::query()->where('kind', 'signup')->update(['active' => true]);
$paths = ['/login', '/register', '/up', '/docs/api', '/events/'.$event->public_id, '/events/'.$event->public_id.'?embed=1', '/api/v1/public/events'];
foreach ($forms as $form) {
    PublicForm::query()->updateOrCreate(['slug' => $form['slug']], $form + ['active' => true]);
    $paths[] = '/f/'.$form['slug'];
}
echo implode("\n", $paths)."\n";
