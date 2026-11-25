<?php

namespace App\Http\Controllers;

use App\Models\Consent;
use App\Models\Person;
use App\Support\Audit\Audit;
use App\Support\Mail\Notifier;
use App\Support\Mail\Optouts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
            // Without a choice mails are sent, so unsubscribing has to be offered.
            'newsletter' => Optouts::latest($model->id, 'newsletter') !== false,
            'all' => Optouts::latest($model->id, 'optout_all') !== false,
            'topics' => Optouts::topics($model),
            'action' => $request->fullUrl(),
        ]);
    }

    public function update(Request $request, string $person): RedirectResponse|Response
    {
        $model = Person::query()->where('public_id', $person)->firstOrFail();
        app()->setLocale($model->locale);
        // One-click unsubscribe of mail programs (RFC 8058): no page, no session.
        if ($request->input('List-Unsubscribe') === 'One-Click') {
            foreach (['newsletter', 'optout_all'] as $kind) {
                Optouts::set($model, $kind, false, 'one_click');
            }

            return response()->noContent();
        }
        $choice = (string) $request->validate(['choice' => ['required', 'regex:/^(newsletter_(on|off)|optout_all|optin_all|deletion_request|topic_(on|off):(series|group):\d+)$/']])['choice'];
        if (preg_match('/^topic_(on|off):(series|group):(\d+)$/', $choice, $m) === 1) {
            abort_unless(collect(Optouts::topics($model))->contains(fn (array $t) => $t['type'] === $m[2] && $t['id'] === (int) $m[3]), 404);
            Optouts::set($model, 'optout_'.$m[2], $m[1] === 'on', 'mail_link', (int) $m[3]);

            return back()->with('status', __('mail.preferences_saved'));
        }

        match ($choice) {
            'newsletter_off', 'newsletter_on' => Optouts::set($model, 'newsletter', $choice === 'newsletter_on', 'mail_link'),
            'optout_all', 'optin_all' => Optouts::set($model, 'optout_all', $choice === 'optin_all', 'mail_link'),
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
