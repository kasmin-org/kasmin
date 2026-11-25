<?php

namespace App\Support\People;

use App\Mail\ConfirmSignUpMail;
use App\Mail\LoginLinkMail;
use App\Models\Consent;
use App\Models\ContactPoint;
use App\Models\Household;
use App\Models\Person;
use App\Models\SignUp;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Auth\LoginLinks;
use App\Support\Mail\Notifier;
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

    /** @param  array{first_name: string, last_name: string, email: string, locale: string, newsletter?: string|null}  $data */
    public function request(array $data): void
    {
        $email = Str::lower(trim($data['email']));
        $personId = ContactPoint::query()->where('type', 'email')->where('value', $email)->value('person_id');
        $user = User::query()->where('email', $email)->first() ?? ($personId !== null ? User::query()->where('person_id', $personId)->first() : null);
        if ($user !== null) {
            ['token' => $token, 'code' => $code] = $this->links->issue($user);
            Mail::to($email)->locale($data['locale'])->send(new LoginLinkMail($data['first_name'], route('login.link', $token), $code, LoginLinks::LINK_MINUTES));

            return;
        }
        $token = Str::random(48);
        SignUp::query()->create(['token_hash' => hash('sha256', $token), 'data' => ['email' => $email] + $data, 'expires_at' => now()->addDays(self::DAYS)]);
        Mail::to($email)->locale($data['locale'])->send(new ConfirmSignUpMail($data['first_name'], route('register.confirm', $token), self::DAYS));
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
            $person = Person::query()->create(['first_name' => $data['first_name'], 'last_name' => $data['last_name'], 'locale' => $data['locale']]);
            $person->contactPoints()->create(['type' => 'email', 'value' => $data['email'], 'purposes' => ['login', 'information', 'invoices'], 'is_primary' => true, 'verified_at' => now()]);
            $household = Household::query()->create(['name' => $data['last_name'], 'locale' => $data['locale']]);
            $household->members()->attach($person->id, ['role' => 'guardian']);
            $household->update(['invoice_recipient_id' => $person->id]);
            if (($data['newsletter'] ?? null) !== null) {
                Consent::query()->create(['person_id' => $person->id, 'kind' => 'newsletter', 'granted' => true, 'source' => 'sign_up', 'text' => $data['newsletter']]);
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
