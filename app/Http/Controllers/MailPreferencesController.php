<?php

namespace App\Http\Controllers;

use App\Models\Consent;
use App\Models\Person;
use App\Support\Audit\Audit;
use App\Support\Mail\Notifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Links in every mail footer (CO03): unsubscribe from the newsletter, no more information mails, request deletion of
 * the data. Signed, time-limited links; opening them changes nothing (mail scanners) – a button press does.
 * A deletion request is not executed automatically but becomes a task for the administrators (CO04).
 */
class MailPreferencesController extends Controller
{
    public function show(Request $request, string $person): View
    {
        $model = Person::query()->where('public_id', $person)->firstOrFail();
        app()->setLocale($model->locale);

        return view('mail.preferences', [
            'person' => $model,
            'newsletter' => ! Consent::query()->where('person_id', $model->id)->where('kind', 'newsletter')->latest('id')->value('granted') === false,
            'action' => $request->fullUrl(),
        ]);
    }

    public function update(Request $request, string $person): RedirectResponse
    {
        $model = Person::query()->where('public_id', $person)->firstOrFail();
        $choice = (string) $request->validate(['choice' => ['required', 'in:newsletter_off,newsletter_on,optout_all,deletion_request']])['choice'];
        app()->setLocale($model->locale);

        match ($choice) {
            'newsletter_off', 'newsletter_on' => Consent::query()->create(['person_id' => $model->id, 'kind' => 'newsletter', 'granted' => $choice === 'newsletter_on', 'source' => 'mail_link']),
            'optout_all' => Consent::query()->create(['person_id' => $model->id, 'kind' => 'optout_all', 'granted' => false, 'source' => 'mail_link']),
            default => $this->deletionRequest($model),
        };

        return back()->with('status', __('mail.preferences_saved'));
    }

    private function deletionRequest(Person $person): void
    {
        Consent::query()->create(['person_id' => $person->id, 'kind' => 'deletion_request', 'granted' => true, 'source' => 'mail_link']);
        Audit::record('privacy.deletion_requested', $person, null, ['source' => 'mail_link']);
        app(Notifier::class)->event('deletion_requests', __('mail.notify_deletion', ['name' => $person->fullName()]), url('/admin/people/'.$person->id));
    }
}
