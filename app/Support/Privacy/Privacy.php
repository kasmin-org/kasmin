<?php

namespace App\Support\Privacy;

use App\Models\AutomationRun;
use App\Models\Consent;
use App\Models\ContactPoint;
use App\Models\Form;
use App\Models\FormResponse;
use App\Models\IncomingMail;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\OutgoingMail;
use App\Models\Participation;
use App\Models\Payment;
use App\Models\Person;
use App\Models\PublicSubmission;
use App\Models\Refund;
use App\Models\RegistrationAnswer;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Data protection (AD71, AD72, CO01, CO04, PE12): what can be deleted for a person and what must be kept, the
 * anonymisation itself, and the retention run for confidential form data (safety sheets, default 7 years after
 * the person's last event).
 */
class Privacy
{
    /**
     * What happens when the person is anonymised (shown before the decision).
     *
     * @return array{delete: array<string, int>, keep: array<string, array{count: int, reason: string}>}
     */
    public function plan(Person $person): array
    {
        $invoiceYears = (int) Settings::get('retention_invoice_years', '7');

        return [
            'delete' => [
                'contact_points' => $person->contactPoints()->count(),
                'login' => $person->user !== null ? 1 : 0,
                'form_responses' => FormResponse::query()->where('person_id', $person->id)->count(),
                'mails' => OutgoingMail::query()->where('person_id', $person->id)->count(),
                'family_links' => $person->households()->count() + $person->guardians()->count() + $person->wards()->count(),
                'groups' => $person->groups()->count(),
            ],
            'keep' => [
                'invoices' => [
                    'count' => Invoice::query()->where('payer_id', $person->id)->where('issued_on', '>=', now()->subYears($invoiceYears)->toDateString())->count(),
                    'reason' => __('privacy.reason_invoices', ['years' => $invoiceYears]),
                ],
                'participations' => [
                    'count' => Participation::query()->where('person_id', $person->id)->count(),
                    'reason' => __('privacy.reason_statistics'),
                ],
                'audit' => ['count' => 1, 'reason' => __('privacy.reason_audit')],
            ],
        ];
    }

    /**
     * Anonymises a person: name replaced, birth date reduced to the year (statistics, PE12), contact data, login,
     * form answers, mails and family/group links deleted. Invoices within the legal retention period are kept.
     */
    public function anonymise(Person $person, string $reason): void
    {
        if ($person->activeRoleKeys() !== []) {
            throw new \RuntimeException(__('privacy.has_roles'));
        }
        DB::transaction(function () use ($person, $reason) {
            $plan = $this->plan($person);
            $this->redact($person);
            $person->contactPoints()->delete();
            if ($person->user !== null) {
                $person->user->loginTokens()->delete();
                $person->user->delete();
            }
            FormResponse::query()->where('person_id', $person->id)->delete();
            OutgoingMail::query()->where('person_id', $person->id)->delete();
            RegistrationAnswer::query()->whereIn('participation_id', $person->participations()->pluck('id'))->delete();
            $person->participations()->update(['price_reason' => null, 'cancel_reason' => null]);
            PublicSubmission::query()->where('person_id', $person->id)->delete();
            $person->households()->detach();
            DB::table('guardianships')->where('child_id', $person->id)->orWhere('guardian_id', $person->id)->delete(); // also ended ones
            $person->groups()->detach();
            $person->forceFill([
                'first_name' => __('privacy.anonymous'), 'last_name' => '#'.$person->id, 'gender' => null,
                'birth_date' => $person->birth_date?->copy()->startOfYear(), 'anonymised_at' => now(),
            ])->save();
            Consent::query()->create(['person_id' => $person->id, 'kind' => 'deletion_done', 'granted' => true, 'source' => 'admin']);
            Audit::record('privacy.anonymised', $person, null, ['reason' => $reason, 'deleted' => $plan['delete'], 'kept' => array_map(fn ($k) => $k['count'], $plan['keep'])]);
        });
    }

    /** Removes the name and other personal data from records that do not belong to the person directly. */
    private function redact(Person $person): void
    {
        $name = $person->fullName();
        $anonymous = __('privacy.anonymous').' #'.$person->id;
        $households = $person->households()->get();

        // Import data: "before" copies and source ids of the person, the contact data and the login.
        $records = fn (string $type, array $ids) => DB::table('import_records')->where('model_type', $type)->whereIn('model_id', $ids)->delete();
        $records(Person::class, [$person->id]);
        $records(ContactPoint::class, $person->contactPoints()->pluck('id')->all());
        $records(User::class, $person->user !== null ? [$person->user->id] : []);
        DB::table('external_ids')->where('model_type', $person->getMorphClass())->where('model_id', $person->id)->delete();

        // Technical copies: webhook payloads, notifications, automation plans, replies.
        DB::table('webhook_deliveries')->where('payload', 'like', '%'.$person->public_id.'%')->delete();
        foreach (DB::table('notification_events')->where('summary', 'like', '%'.addcslashes($name, '%_').'%')->get(['id', 'summary']) as $row) {
            DB::table('notification_events')->where('id', $row->id)->update(['summary' => str_replace($name, $anonymous, (string) $row->summary)]);
        }
        $replace = fn (mixed $value) => is_string($value) ? str_replace($name, $anonymous, $value) : $value;
        foreach (AutomationRun::query()->lazyById() as $run) {
            $plan = array_map(fn (array $item) => array_map($replace, $item), $run->plan);
            if ($plan !== $run->plan) {
                $run->forceFill(['plan' => $plan])->save();
            }
        }
        IncomingMail::query()->where('person_id', $person->id)->delete();

        // Invoices after the retention period: no name, address or bank data any more.
        $limit = now()->subYears((int) Settings::get('retention_invoice_years', '7'))->toDateString();
        $old = Invoice::query()->where('issued_on', '<', $limit);
        $paid = (clone $old)->where('payer_id', $person->id)->pluck('id');
        Invoice::query()->whereIn('id', $paid)->update(['payer_name' => $anonymous, 'payer_address' => null]);
        Payment::query()->whereIn('invoice_id', $paid)->update(['payer_name' => null, 'iban' => null, 'reference' => null, 'note' => null]);
        Refund::query()->where('payer_id', $person->id)->where('created_at', '<', $limit)->update(['payer_name' => null, 'iban' => null, 'reason' => $anonymous]);
        foreach (InvoiceLine::query()->where('person_id', $person->id)->whereIn('invoice_id', $old->pluck('id'))->get() as $line) {
            $line->forceFill(['description' => str_replace($name, $anonymous, $line->description)])->saveQuietly();
        }

        // A family without other members: no name, address or emergency contacts any more.
        foreach ($households as $household) {
            if ($household->members()->whereKeyNot($person->id)->doesntExist()) {
                $household->emergencyContacts()->delete();
                RegistrationAnswer::query()->where('household_id', $household->id)->delete();
                Payment::query()->where('household_id', $household->id)->whereNull('invoice_id')->where('paid_on', '<', $limit)->update(['payer_name' => null, 'iban' => null, 'reference' => null, 'note' => null]);
                $household->forceFill(['name' => $anonymous, 'street' => null, 'postal_code' => null, 'city' => null])->save();
            }
        }
    }

    /**
     * Open deletion requests (CO04): requested, not yet done.
     *
     * @return Collection<int, Person>
     */
    public function openRequests()
    {
        $done = Consent::query()->where('kind', 'deletion_done')->pluck('person_id');

        return Person::query()->whereIn('id', Consent::query()->where('kind', 'deletion_request')->where('granted', true)->pluck('person_id'))
            ->whereNotIn('id', $done)->whereNull('anonymised_at')->get();
    }

    /**
     * Retention run (AD72, CO01): answers of forms with confidential fields of people whose last event ended more
     * than the configured years ago (default 7), or who never took part and whose answer is that old.
     *
     * @return list<array{person: Person, responses: int, since: string}>
     */
    public function retentionPlan(): array
    {
        $years = (int) Settings::get('retention_safety_years', '7');
        $limit = now()->subYears($years);
        $forms = Form::query()->whereHas('fields', fn ($q) => $q->whereIn('sensitivity', ['health', 'identity']))->pluck('id');
        if ($forms->isEmpty()) {
            return [];
        }
        $result = [];
        $personIds = FormResponse::query()->whereIn('form_id', $forms)->distinct()->pluck('person_id');
        foreach (Person::query()->whereIn('id', $personIds)->get() as $person) {
            $lastEvent = Participation::query()->where('participations.person_id', $person->id)->join('events', 'events.id', '=', 'participations.event_id')->max('events.ends_at');
            $lastAnswer = FormResponse::query()->where('person_id', $person->id)->whereIn('form_id', $forms)->max('updated_at');
            $since = $lastEvent ?? $lastAnswer;
            $active = Participation::query()->where('person_id', $person->id)->whereIn('status', ['registered', 'confirmed', 'offered', 'waitlist', 'requested'])
                ->whereHas('event', fn ($q) => $q->where('ends_at', '>=', now()))->exists();
            if ($since !== null && ! $active && Carbon::parse($since)->lt($limit)) {
                $result[] = ['person' => $person, 'responses' => FormResponse::query()->where('person_id', $person->id)->whereIn('form_id', $forms)->count(), 'since' => (string) $since];
            }
        }

        return $result;
    }

    /** Deletes what the retention plan lists; returns the number of deleted answers. */
    public function runRetention(): int
    {
        $forms = Form::query()->whereHas('fields', fn ($q) => $q->whereIn('sensitivity', ['health', 'identity']))->pluck('id');
        $deleted = 0;
        foreach ($this->retentionPlan() as $entry) {
            $count = FormResponse::query()->where('person_id', $entry['person']->id)->whereIn('form_id', $forms)->delete();
            $deleted += $count;
            Audit::record('privacy.retention_deleted', $entry['person'], null, ['form_responses' => $count, 'since' => $entry['since']], 'system');
        }

        return $deleted;
    }
}
