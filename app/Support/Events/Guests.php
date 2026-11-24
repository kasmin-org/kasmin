<?php

namespace App\Support\Events;

use App\Mail\GuestInvitationMail;
use App\Models\Event;
use App\Models\GuestInvitation;
use App\Models\Participation;
use App\Support\Audit\Audit;
use App\Support\Forms\RegistrationAnswers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Guests of adult participants (PA05): a personal link, own registration, own invoice, no membership.
 */
class Guests
{
    public const ROLE = 'guest';

    public function __construct(private readonly PublicForms $forms, private readonly Registrations $registrations, private readonly RegistrationAnswers $answers) {}

    /** How many guests can still be invited. */
    public function left(Participation $participation): int
    {
        $event = $participation->event;
        if ($event === null || $event->max_guests === 0 || ! $event->isOpenForRegistration() || $participation->guest_of_id !== null || ! in_array($participation->status, ['registered', 'confirmed'], true)) {
            return 0;
        }
        $used = Participation::query()->where('guest_of_id', $participation->id)->whereNotIn('status', ['cancelled', 'declined'])->count()
            + GuestInvitation::query()->where('participation_id', $participation->id)->whereNull('used_at')->where('expires_at', '>', now())->count();

        return max(0, $event->max_guests - $used);
    }

    public function invite(Participation $participation, string $email, string $locale): void
    {
        $token = Str::random(48);
        DB::transaction(function () use ($participation, $email, $token) {
            $locked = Participation::query()->whereKey($participation->id)->lockForUpdate()->with('event')->firstOrFail();
            if ($this->left($locked) < 1) {
                throw RegistrationException::because('no_guests');
            }
            GuestInvitation::query()->create(['participation_id' => $locked->id, 'email' => Str::lower(trim($email)), 'token_hash' => hash('sha256', $token), 'expires_at' => $locked->event !== null ? $locked->event->starts_at : now()->addDays(14)]);
            Audit::record('guest.invited', $locked);
        });
        Mail::to($email)->locale($locale)->send(new GuestInvitationMail((string) $participation->person?->fullName(), (string) $participation->event?->title, route('public.guest', $token)));
    }

    public function find(string $token): ?GuestInvitation
    {
        $invitation = GuestInvitation::query()->where('token_hash', hash('sha256', $token))->with('participation.event', 'participation.person')->first();

        return $invitation?->usable() === true ? $invitation : null;
    }

    /**
     * The invited address proved itself by opening the personal link.
     *
     * @param  array<string, mixed>  $data
     */
    public function accept(GuestInvitation $invitation, array $data): Participation
    {
        return DB::transaction(function () use ($invitation, $data) {
            $invitation = GuestInvitation::query()->whereKey($invitation->id)->whereNull('used_at')->lockForUpdate()->with('participation.event')->firstOrFail();
            $host = $invitation->participation ?? throw RegistrationException::because('not_open');
            /** @var Event $event */
            $event = $host->event;
            $person = $this->forms->person(['email' => (string) $invitation->email] + $data);
            $participation = $this->registrations->register($event, $person, self::ROLE, $person);
            $participation->forceFill(['guest_of_id' => $host->id])->save();
            $form = $this->answers->form($event, self::ROLE);
            if ($form !== null) {
                $this->answers->save($participation, $form, (array) ($data['values'] ?? []), $person);
            }
            $invitation->forceFill(['used_at' => now(), 'email' => null])->save();

            return $participation;
        });
    }

    public function expire(): int
    {
        return GuestInvitation::query()->whereNull('used_at')->whereNotNull('email')->where('expires_at', '<', now())->update(['email' => null]);
    }
}
