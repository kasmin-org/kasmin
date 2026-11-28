<?php

namespace App\Support\Auth;

use App\Models\Event;
use App\Models\LoginToken;
use App\Models\Person;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Issues and redeems one-time login links and codes (L01–L04). Only hashes are stored.
 *
 * The code people type has six Base32H digits (B12, about a billion possibilities). Against guessing (B13): failed
 * codes are counted per person over all open codes – after five within 30 minutes the code entry is closed for that
 * person (the link in the mail still works) –, a new code ends the codes of older mails, and login mails to one
 * address are limited, whatever the IP address of the requests.
 */
class LoginLinks
{
    public const LINK_MINUTES = 30;

    public const MAIL_LINK_DAYS = 30;

    /** A link in a mail about an event is valid until the event has ended, but never longer than this (B14). */
    public const MAIL_LINK_MAX_DAYS = 365;

    /** Statuses in which somebody counts as signed up for an event: registered, on the waitlist or with an open offer. */
    public const SIGNED_UP = ['requested', 'registered', 'confirmed', 'waitlist', 'offered'];

    public const CODE_LENGTH = 6;

    /** Failed codes per person before the code entry closes, and for how long. */
    public const CODE_FAILURES = 5;

    public const CODE_LOCK_SECONDS = 30 * 60;

    /** Login and sign-up mails to one address within the window. */
    public const MAILS_PER_ADDRESS = 3;

    public const MAIL_WINDOW_SECONDS = 15 * 60;

    /**
     * @return array{token: string, code: string, model: LoginToken}
     */
    public function issue(User $user, string $purpose = 'login', string $scope = 'full', ?int $minutes = null, ?int $eventId = null): array
    {
        $token = Str::random(48);
        $code = Base32H::random(self::CODE_LENGTH);
        $minutes ??= $purpose === 'mail_link' ? self::MAIL_LINK_DAYS * 24 * 60 : self::LINK_MINUTES;
        if ($purpose === 'login') {
            // Only the code of the newest mail counts; the links of older mails keep working until they expire.
            $user->loginTokens()->where('purpose', 'login')->whereNull('used_at')->whereNotNull('code_hash')->update(['code_hash' => null]);
        }

        $model = $user->loginTokens()->create([
            'token_hash' => hash('sha256', $token),
            'code_hash' => hash('sha256', $user->id.'|'.$code),
            'purpose' => $purpose,
            'scope' => $scope,
            'expires_at' => now()->addMinutes($minutes),
            'event_id' => $eventId,
            'created_ip' => app()->runningInConsole() ? null : request()->ip(),
        ]);

        Audit::record('login.token_issued', $user, null, ['token_id' => $model->id, 'purpose' => $purpose, 'scope' => $scope], 'system');

        return ['token' => $token, 'code' => Base32H::format($code), 'model' => $model];
    }

    /**
     * Whether one more login or sign-up mail may go to this address now (counts it). Requests beyond the limit get
     * the same answer as all others, so nobody learns whether the address is known.
     */
    public function mayMail(string $email): bool
    {
        $key = 'login-mail|'.hash('sha256', mb_strtolower(trim($email)));
        if (RateLimiter::tooManyAttempts($key, self::MAILS_PER_ADDRESS)) {
            return false;
        }
        RateLimiter::hit($key, self::MAIL_WINDOW_SECONDS);

        return true;
    }

    public function findByToken(string $token): ?LoginToken
    {
        $model = LoginToken::query()->where('token_hash', hash('sha256', $token))->first();
        if ($model === null || ! $model->isUsable()) {
            return null;
        }
        // Beyond the usual 30 days a link about an event counts only while the person is still signed up for it: a
        // cancellation, a cancelled event or the end of the guardianship shortens it at once (B14).
        if ($model->purpose === 'mail_link' && $model->event_id !== null && now()->gt($model->created_at->copy()->addDays(self::MAIL_LINK_DAYS))) {
            $person = $model->user?->person;
            $event = Event::query()->find($model->event_id);
            if ($person === null || $event === null || ! $this->signedUp($person, $event)) {
                return null;
            }
        }

        return $model;
    }

    /**
     * How long a link in a mail is valid (B14): until the end of the event the mail is about, if the person or one of
     * their children is signed up for it – never shorter than 30 days and never longer than a year; otherwise 30 days.
     */
    public function mailLinkMinutes(Person $person, ?Event $event): int
    {
        $minutes = self::MAIL_LINK_DAYS * 24 * 60;
        if ($event === null || ! $this->signedUp($person, $event)) {
            return $minutes;
        }
        $until = (int) ceil(now()->diffInMinutes($event->ends_at, false));

        return max($minutes, min($until, self::MAIL_LINK_MAX_DAYS * 24 * 60));
    }

    /** The person or a child they are the legal guardian of is registered, on the waitlist or has an open offer. */
    public function signedUp(Person $person, Event $event): bool
    {
        if ($event->status === 'cancelled' || $event->ends_at->isPast()) {
            return false;
        }
        $people = [$person->id, ...$person->wards()->pluck('people.id')->all()];

        return $event->participations()->whereIn('person_id', $people)->whereIn('status', self::SIGNED_UP)->exists();
    }

    /** Ends all links in older mails of this person (B14); login links of the last 30 minutes stay. */
    public function revokeMailLinks(User $user): int
    {
        $count = $user->loginTokens()->where('purpose', 'mail_link')->where('expires_at', '>', now())->update(['expires_at' => now()]);
        Audit::record('login.mail_links_revoked', $user, null, ['count' => $count]);

        return $count;
    }

    public function redeemCode(User $user, string $code): ?LoginToken
    {
        $key = 'login-code-failures|'.$user->id;
        if (RateLimiter::tooManyAttempts($key, self::CODE_FAILURES)) {
            Audit::record('login.code_locked', $user, null, [], 'system');

            return null;
        }
        $code = Base32H::normalize($code);
        $model = strlen($code) === self::CODE_LENGTH
            ? $user->loginTokens()->whereNull('used_at')->where('expires_at', '>', now())->where('purpose', 'login')->whereNotNull('code_hash')->latest('id')->first()
            : null;
        if ($model !== null && $model->isUsable() && hash_equals((string) $model->getAttribute('code_hash'), hash('sha256', $user->id.'|'.$code))) {
            RateLimiter::clear($key);

            return $this->consume($model);
        }
        // Counted per person over all codes, so many requested mails do not give more guesses.
        RateLimiter::hit($key, self::CODE_LOCK_SECONDS);
        $model?->increment('failed_attempts');

        return null;
    }

    public function consume(LoginToken $model): LoginToken
    {
        // Mail links (newsletter, invoice) may be used more than once until they expire; all other links once.
        if ($model->purpose !== 'mail_link') {
            $model->forceFill(['used_at' => now()])->save();
        }

        return $model;
    }
}
