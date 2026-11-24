<?php

namespace App\Support\People;

use App\Mail\GuardianInvitationMail;
use App\Mail\LoginLinkMail;
use App\Models\GuardianInvitation;
use App\Models\Household;
use App\Models\Person;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Auth\LoginLinks;
use App\Support\Events\PublicForms;
use App\Support\Mail\Composer;
use App\Support\Mail\Notifier;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Legal guardians of a family: invite further ones (RG07), own login of a child (GP22, PE04), end at the legal age (PE05).
 */
class Guardians
{
    public const INVITATION_DAYS = 14;

    public function __construct(private readonly PublicForms $forms) {}

    /** How many guardians can still be invited. */
    public function left(Household $household): int
    {
        $open = GuardianInvitation::query()->where('household_id', $household->id)->whereNull('used_at')->where('expires_at', '>', now())->count();

        return max(0, Settings::int('max_guardians') - $household->guardians()->count() - $open);
    }

    /** @param  array{first_name: string, last_name: string, email: string}  $data */
    public function invite(Household $household, Person $by, array $data): void
    {
        $token = Str::random(48);
        DB::transaction(function () use ($household, $by, $data, $token) {
            Household::query()->whereKey($household->id)->lockForUpdate()->firstOrFail();
            if ($this->left($household) < 1) {
                throw new RuntimeException(__('portal.guardians_full'));
            }
            GuardianInvitation::query()->create([
                'household_id' => $household->id, 'invited_by_id' => $by->id, 'token_hash' => hash('sha256', $token),
                'data' => ['email' => Str::lower(trim($data['email']))] + $data, 'expires_at' => now()->addDays(self::INVITATION_DAYS),
            ]);
            Audit::record('guardian.invited', $household);
        });
        app(Notifier::class)->event('registrations', __('portal.notify_guardian_invited', ['name' => $by->fullName(), 'family' => $household->name]), url('/admin/households/'.$household->id.'/edit'));
        Mail::to($data['email'])->locale($by->locale)->send(new GuardianInvitationMail($by->fullName(), route('guardian.invitation', $token), self::INVITATION_DAYS));
    }

    public function find(string $token): ?GuardianInvitation
    {
        return GuardianInvitation::query()->where('token_hash', hash('sha256', $token))->whereNull('used_at')->where('expires_at', '>', now())->with('household', 'invitedBy')->first();
    }

    /** The personal link proves the address. The new guardian gets the children the inviting guardian has now. */
    public function accept(GuardianInvitation $invitation): Person
    {
        return DB::transaction(function () use ($invitation) {
            $invitation = GuardianInvitation::query()->whereKey($invitation->id)->whereNull('used_at')->lockForUpdate()->with('household', 'invitedBy')->firstOrFail();
            $household = $invitation->household ?? throw new RuntimeException('Family missing');
            $by = $invitation->invitedBy;
            // Rights of the inviting person at this moment, e.g. no longer a guardian since then.
            if ($by === null || $invitation->data === null || ! $household->guardians()->whereKey($by->id)->exists()) {
                throw new RuntimeException(__('public.invalid'));
            }
            $person = $this->forms->person($invitation->data + ['locale' => $by->locale]);
            $children = $by->wards()->whereHas('households', fn ($q) => $q->whereKey($household->id))->get();
            if ($children->contains('id', $person->id)) {
                throw new RuntimeException(__('public.invalid'));
            }
            if (! $household->members()->whereKey($person->id)->exists()) {
                $household->members()->attach($person->id, ['role' => 'guardian']);
            } else {
                $household->members()->updateExistingPivot($person->id, ['role' => 'guardian']);
            }
            foreach ($children as $child) {
                $child->guardians()->syncWithoutDetaching([$person->id]);
            }
            $invitation->forceFill(['used_at' => now(), 'data' => null])->save();
            Audit::record('guardian.added', $person, null, ['household_id' => $household->id, 'children' => $children->count(), 'invited_by' => $by->id], 'system');
            app(Notifier::class)->event('registrations', __('portal.notify_guardian_added', ['name' => $person->fullName(), 'family' => $household->name]), url('/admin/households/'.$household->id.'/edit'));

            return $person;
        });
    }

    /** May this child get an own login now (PE04)? */
    public function mayHaveLogin(Person $child): bool
    {
        return $child->user === null && ($child->ageAt() ?? 0) >= Settings::int('min_login_age');
    }

    public function createLogin(Person $child, Person $by, string $email): void
    {
        $email = Str::lower(trim($email));
        if (! $this->mayHaveLogin($child) || User::query()->where('email', $email)->exists()) {
            throw new RuntimeException(__('portal.login_not_possible'));
        }
        DB::transaction(function () use ($child, $email) {
            if (! $child->contactPoints()->where('type', 'email')->where('value', $email)->exists()) {
                $child->contactPoints()->create(['type' => 'email', 'value' => $email, 'purposes' => ['login'], 'is_primary' => $child->primaryEmail() === null]);
            }
            User::query()->create(['name' => $child->fullName(), 'email' => $email, 'password' => Str::password(64), 'person_id' => $child->id]);
        });
        Audit::record('child_login.created', $child, null, ['by' => $by->id]);
        $user = User::query()->where('person_id', $child->id)->firstOrFail();
        ['token' => $token, 'code' => $code] = app(LoginLinks::class)->issue($user);
        Mail::to($email)->locale($child->locale)->send(new LoginLinkMail($child->first_name, route('login.link', $token), $code, LoginLinks::LINK_MINUTES));
    }

    /** Guardianships end at the configured age; both sides are told (PE05). */
    public function endAtAge(): int
    {
        $limit = now()->subYears(Settings::int('guardian_until_age'))->toDateString();
        $ended = 0;
        foreach (Person::query()->whereNull('anonymised_at')->whereDate('birth_date', '<=', $limit)->whereHas('guardians')->with('guardians')->get() as $person) {
            $guardians = $person->guardians;
            DB::transaction(function () use ($person) {
                DB::table('guardianships')->where('child_id', $person->id)->whereNull('ends_on')->update(['ends_on' => now()->subDay()->toDateString(), 'updated_at' => now()]);
                Audit::record('guardianship.ended', $person, null, ['reason' => 'age'], 'system');
            });
            foreach ([$person, ...$guardians->all()] as $recipient) {
                app(Composer::class)->queue('guardianship_ended', $recipient, ['children' => [$person]]);
            }
            $ended++;
        }

        return $ended;
    }

    public function expire(): int
    {
        return GuardianInvitation::query()->whereNull('used_at')->whereNotNull('data')->where('expires_at', '<', now())->update(['data' => null]);
    }
}
