<?php

namespace App\Support\Events;

use App\Mail\ConfirmSubmissionMail;
use App\Models\Consent;
use App\Models\ContactPoint;
use App\Models\Form;
use App\Models\Group;
use App\Models\Person;
use App\Models\PublicForm;
use App\Models\PublicSubmission;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Forms\RegistrationAnswers;
use App\Support\Mail\Composer;
use App\Support\Mail\Notifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Public forms: nothing happens before the address is confirmed by the link in the mail (double opt-in, PF02). Existing
 * people are recognised by address and name; their data is never overwritten by a public form.
 */
class PublicForms
{
    public const CONFIRM_DAYS = 3;

    public function __construct(private readonly RegistrationAnswers $answers, private readonly Registrations $registrations) {}

    /** Questions shown on the form. */
    public function questions(PublicForm $form): ?Form
    {
        if ($form->kind === 'event') {
            return $form->event !== null ? $this->answers->form($form->event, (string) $form->role_key) : null;
        }

        return $form->form()->where('kind', 'registration')->where('active', true)->with('fields')->first();
    }

    /** @param  array<string, mixed>  $data */
    public function submit(PublicForm $form, array $data): void
    {
        $token = Str::random(48);
        PublicSubmission::query()->create([
            'public_form_id' => $form->id, 'token_hash' => hash('sha256', $token), 'data' => $data, 'expires_at' => now()->addDays(self::CONFIRM_DAYS),
        ]);
        Mail::to((string) $data['email'])->locale((string) $data['locale'])
            ->send(new ConfirmSubmissionMail((string) $data['first_name'], $form->titleText(), route('public.confirm', $token), self::CONFIRM_DAYS));
    }

    public function find(string $token): ?PublicSubmission
    {
        return PublicSubmission::query()->where('token_hash', hash('sha256', $token))->where('status', 'unconfirmed')->where('expires_at', '>', now())->with('publicForm.event')->first();
    }

    /** The address is confirmed: carry out, or wait for the approval. */
    public function confirm(PublicSubmission $submission): PublicSubmission
    {
        return DB::transaction(function () use ($submission) {
            $submission = PublicSubmission::query()->whereKey($submission->id)->where('status', 'unconfirmed')->lockForUpdate()->firstOrFail();
            $form = $submission->publicForm ?? throw new RuntimeException('Form missing');
            $submission->forceFill(['confirmed_at' => now()])->save();
            $data = $submission->data ?? [];
            if ($form->kind === 'application' && $form->needs_approval) {
                $submission->forceFill(['status' => 'pending'])->save();
                app(Notifier::class)->event('registrations', __('public.notify_application', ['form' => $form->titleText(), 'name' => trim($data['first_name'].' '.$data['last_name'])]), url('/admin/public-submissions'));

                return $submission;
            }
            $this->carryOut($submission, $form);

            return $submission;
        });
    }

    public function approve(PublicSubmission $submission): void
    {
        DB::transaction(function () use ($submission) {
            $submission = PublicSubmission::query()->whereKey($submission->id)->where('status', 'pending')->lockForUpdate()->firstOrFail();
            $submission->forceFill(['decided_by_id' => auth()->id()])->save();
            $this->carryOut($submission, $submission->publicForm ?? throw new RuntimeException('Form missing'));
        });
    }

    public function decline(PublicSubmission $submission, ?string $note): void
    {
        if ($submission->status === 'pending') {
            $submission->forceFill(['status' => 'declined', 'note' => $note, 'decided_by_id' => auth()->id(), 'data' => null])->save();
            Audit::record('public_form.declined', $submission);
        }
    }

    /** Unconfirmed submissions expire with their data. */
    public function expire(): int
    {
        return PublicSubmission::query()->where('status', 'unconfirmed')->where('expires_at', '<', now())->delete();
    }

    private function carryOut(PublicSubmission $submission, PublicForm $form): void
    {
        $data = $submission->data ?? [];
        $person = $this->person($data);
        $values = (array) ($data['values'] ?? []);
        $submission->person_id = $person->id;
        if ($form->kind === 'event') {
            $event = $form->event ?? throw new RuntimeException('Event missing');
            try {
                $participation = $this->registrations->register($event, $person, (string) $form->role_key, $person);
            } catch (RegistrationException $e) {
                $submission->forceFill(['status' => 'failed', 'note' => Str::limit($e->getMessage(), 250), 'data' => null])->save();

                return;
            }
            $questions = $this->questions($form);
            if ($questions !== null) {
                $this->answers->save($participation, $questions, $values, $person);
            }
            $submission->setAttribute('participation_id', $participation->id);
        }
        if ($form->kind === 'interest') {
            Consent::query()->create(['person_id' => $person->id, 'kind' => 'newsletter', 'granted' => true, 'source' => 'public_form']);
        }
        if ($form->group_id !== null && Group::query()->whereKey($form->group_id)->where('kind', 'manual')->exists() && ! $person->groups()->whereKey($form->group_id)->exists()) {
            $person->groups()->attach($form->group_id, ['starts_on' => now()->toDateString(), 'source' => 'public_form']);
        }
        if ($form->kind === 'application') {
            if ($form->membership_type_id !== null && ! $person->memberships()->where('membership_type_id', $form->membership_type_id)->whereNull('ends_on')->exists()) {
                $person->memberships()->create(['membership_type_id' => $form->membership_type_id, 'starts_on' => now()->toDateString()]);
            }
            app(Composer::class)->queue('application_welcome', $person);
        }
        $submission->forceFill(['status' => 'done', 'data' => null])->save();
        Audit::record('public_form.done', $person, null, ['form' => $form->slug], 'system');
    }

    /**
     * The person of a confirmed address, if the name fits; otherwise a new person.
     *
     * @param  array<string, mixed>  $data
     */
    public function person(array $data): Person
    {
        $email = Str::lower(trim((string) $data['email']));
        $same = fn (Person $p) => $p->getAttribute('anonymised_at') === null && Str::lower($p->fullName()) === Str::lower(trim($data['first_name'].' '.$data['last_name']));
        $ids = ContactPoint::query()->where('type', 'email')->where('value', $email)->pluck('person_id');
        $user = User::query()->where('email', $email)->first();
        $person = Person::query()->whereIn('id', $ids->push($user?->person_id)->filter())->get()->first($same);
        if ($person !== null) {
            if ($person->birth_date === null && filled($data['birth_date'] ?? null)) {
                $person->update(['birth_date' => $data['birth_date']]); // missing, so nothing is overwritten
            }

            return $person;
        }
        $person = Person::query()->create(['first_name' => $data['first_name'], 'last_name' => $data['last_name'], 'birth_date' => $data['birth_date'] ?? null, 'locale' => $data['locale'] ?? 'de']);
        $person->contactPoints()->create(['type' => 'email', 'value' => $email, 'purposes' => ['login', 'information', 'invoices'], 'is_primary' => true]);
        if (filled($data['phone'] ?? null)) {
            $person->contactPoints()->create(['type' => 'phone', 'value' => $data['phone'], 'is_primary' => true]);
        }
        if ($user === null) {
            User::query()->create(['name' => $person->fullName(), 'email' => $email, 'password' => Str::password(64), 'person_id' => $person->id]);
        }

        return $person;
    }
}
