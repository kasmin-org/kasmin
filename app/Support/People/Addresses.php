<?php

namespace App\Support\People;

use App\Mail\AddressChangedMail;
use App\Mail\ConfirmAddressMail;
use App\Models\ContactPoint;
use App\Models\EmailChange;
use App\Models\Person;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * People change their own e-mail addresses (F03). A new address counts after it was confirmed, the old one is always told.
 */
class Addresses
{
    public const HOURS = 24;

    /** The answer is always the same: whether another account uses the address is not revealed. */
    public function request(Person $person, string $email, ?ContactPoint $replaces): void
    {
        $email = Str::lower(trim($email));
        EmailChange::query()->where('person_id', $person->id)->delete();
        if (User::query()->where('email', $email)->where('person_id', '!=', $person->id)->exists()) {
            return;
        }
        $token = Str::random(48);
        EmailChange::query()->create(['person_id' => $person->id, 'contact_point_id' => $replaces?->id, 'token_hash' => hash('sha256', $token), 'email' => $email, 'expires_at' => now()->addHours(self::HOURS)]);
        Audit::record('address.change_requested', $person);
        Mail::to($email)->locale($person->locale)->send(new ConfirmAddressMail($person->fullName(), route('address.confirm', $token)));
    }

    public function find(string $token): ?EmailChange
    {
        return EmailChange::query()->where('token_hash', hash('sha256', $token))->where('expires_at', '>', now())->with('person')->first();
    }

    public function confirm(EmailChange $change): void
    {
        $person = $change->person;
        if ($person === null || User::query()->where('email', $change->email)->where('person_id', '!=', $person->id)->exists()) {
            $change->delete();

            return;
        }
        $old = $person->contactPoints()->where('type', 'email')->pluck('value')->all();
        DB::transaction(function () use ($change, $person) {
            $point = $change->contact_point_id !== null ? $person->contactPoints()->where('type', 'email')->find($change->contact_point_id) : null;
            $before = $point?->value;
            $purposes = ['login', 'information', 'invoices'];
            $existing = $person->contactPoints()->where('type', 'email')->where('value', $change->email)->first();
            if ($existing !== null) {
                $existing->update(['verified_at' => now()]);
            } elseif ($point !== null) {
                $point->update(['value' => $change->email, 'verified_at' => now()]);
            } else {
                $person->contactPoints()->create(['type' => 'email', 'value' => $change->email, 'purposes' => $purposes, 'is_primary' => $person->primaryEmail() === null, 'verified_at' => now()]);
            }
            $user = $person->user;
            if ($user !== null && $before !== null && Str::lower($user->email) === $before) {
                $user->forceFill(['email' => $change->email])->save();
                $user->loginTokens()->delete(); // links sent to the old address end
            }
            EmailChange::query()->where('person_id', $person->id)->delete();
            Audit::record('address.changed', $person, null, null, 'user', $user?->id);
        });
        foreach (array_unique($old) as $address) {
            if ($address !== $change->email) {
                Mail::to($address)->locale($person->locale)->send(new AddressChangedMail($person->fullName()));
            }
        }
    }

    public function expire(): int
    {
        return EmailChange::query()->where('expires_at', '<', now())->delete();
    }
}
