<?php

namespace App\Support\People;

use App\Mail\ConfirmSignUpMail;
use App\Mail\LoginLinkMail;
use App\Models\Consent;
use App\Models\ContactPoint;
use App\Models\Household;
use App\Models\Group;
use App\Models\Person;
use App\Models\PublicForm;
use App\Models\SignUp;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Auth\LoginLinks;
use App\Support\Auth\ProofOfWork;
use App\Support\Language;
use App\Support\Mail\Notifier;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Sign-up of legal guardians (RG01–RG03): nothing is created before the address is confirmed; unconfirmed sign-ups expire.
 */
class SignUps
{
    public const DAYS = 3;

    public function __construct(private readonly LoginLinks $links) {}

    /**
     * The sign-up form of a family account (B16): name, address, consent; then a link to confirm. The answer is the
     * same whether or not the address is known.
     */
    public function fromRequest(PublicForm $form, Request $request): RedirectResponse
    {
        abort_unless($form->kind === 'signup' && $form->active, 404);
        // Honeypot: bots fill every field.
        if ((string) $request->input('website') !== '') {
            return redirect()->route('login.code');
        }
        abort_unless($form->state() === 'open', 403);
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:254'],
            'privacy' => ['accepted'],
        ]);
        if (! ProofOfWork::valid($request->input('pow'))) {
            return back()->withInput()->withErrors(['pow' => __('public.check_failed')]);
        }
        $this->request([
            'first_name' => (string) $data['first_name'], 'last_name' => (string) $data['last_name'], 'email' => (string) $data['email'], 'locale' => Language::current(),
            'newsletter' => $form->ask_newsletter && Settings::bool('newsletter_ask') && $request->boolean('newsletter') ? (string) __('public.newsletter_consent') : null,
            'public_form_id' => $form->id,
        ]);

        return redirect()->route('login.code')->with('email', $data['email'])->with('status', __('auth_link.sent'));
    }

    /** @param  array{first_name: string, last_name: string, email: string, locale: string, newsletter?: string|null, public_form_id?: int}  $data */
    public function request(array $data): void
    {
        $email = Str::lower(trim($data['email']));
        if (! $this->links->mayMail($email)) {
            return; // Same answer as always; the address gets no further mail for a while.
        }
        $personId = ContactPoint::query()->where('type', 'email')->where('value', $email)->value('person_id');
        $user = User::query()->where('email', $email)->first() ?? ($personId !== null ? User::query()->where('person_id', $personId)->first() : null);
        if ($user !== null) {
            ['token' => $token, 'code' => $code] = $this->links->issue($user);
            Mail::to($email)->locale(Language::current())->send(new LoginLinkMail($data['first_name'], route('login.link', $token), $code, LoginLinks::LINK_MINUTES));

            return;
        }
        $token = Str::random(48);
        SignUp::query()->create(['token_hash' => hash('sha256', $token), 'data' => ['email' => $email] + $data, 'expires_at' => now()->addDays(self::DAYS)]);
        Mail::to($email)->locale(Language::current())->send(new ConfirmSignUpMail(trim($data['first_name'].' '.$data['last_name']), route('register.confirm', $token), self::DAYS));
    }

    public function find(string $token): ?SignUp
    {
        return SignUp::query()->where('token_hash', hash('sha256', $token))->where('expires_at', '>', now())->first();
    }

    /** Creates person, family and login; returns a login token, because the link has proved the address (RG02). */
    public function confirm(SignUp $signUp): string
    {
        $user = DB::transaction(function () use ($signUp) {
            $signUp = SignUp::query()->whereKey($signUp->id)->lockForUpdate()->firstOrFail();
            $data = $signUp->data;
            SignUp::query()->whereKey($signUp->id)->delete();
            $existing = User::query()->where('email', $data['email'])->first();
            if ($existing !== null) {
                return $existing; // signed up twice before confirming
            }
            $person = Person::query()->create(['first_name' => $data['first_name'], 'last_name' => $data['last_name']]);
            $person->contactPoints()->create(['type' => 'email', 'value' => $data['email'], 'purposes' => ['login', 'information', 'invoices'], 'is_primary' => true, 'verified_at' => now()]);
            $household = Household::query()->create(['name' => $data['last_name']]);
            $household->members()->attach($person->id, ['role' => 'guardian']);
            $household->update(['invoice_recipient_id' => $person->id]);
            if (($data['newsletter'] ?? null) !== null) {
                Consent::query()->create(['person_id' => $person->id, 'kind' => 'newsletter', 'granted' => true, 'source' => 'sign_up', 'text' => $data['newsletter']]);
            }
            // The group of the sign-up form, if it has one (B16).
            $groupId = isset($data['public_form_id']) ? PublicForm::query()->whereKey($data['public_form_id'])->value('group_id') : null;
            if ($groupId !== null && Group::query()->whereKey($groupId)->where('kind', 'manual')->exists()) {
                $person->groups()->attach($groupId, ['starts_on' => now()->toDateString(), 'source' => 'public_form']);
            }
            Audit::record('registration.guardian', $person, null, ['source' => 'public_form'], 'system');
            app(Notifier::class)->event('registrations', __('portal.notify_signup', ['name' => $person->fullName()]), url('/admin/people/'.$person->id));
            if (app(Duplicates::class)->of($person) !== []) {
                app(Notifier::class)->event('registrations', __('portal.notify_duplicate', ['name' => $person->fullName()]), url('/admin/duplicates'));
            }

            return User::query()->create(['name' => $person->fullName(), 'email' => $data['email'], 'password' => Str::password(64), 'person_id' => $person->id]);
        });

        return $this->links->issue($user)['token'];
    }

    public function expire(): int
    {
        return SignUp::query()->where('expires_at', '<', now())->delete();
    }
}
