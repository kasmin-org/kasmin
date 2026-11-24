<?php

namespace App\Support\Privacy;

use App\Models\Consent;
use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Invoice;
use App\Models\OutgoingMail;
use App\Models\Participation;
use App\Models\Person;
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
        DB::transaction(function () use ($person, $reason) {
            $plan = $this->plan($person);
            $person->contactPoints()->delete();
            if ($person->user !== null) {
                $person->user->loginTokens()->delete();
                $person->user->delete();
            }
            FormResponse::query()->where('person_id', $person->id)->delete();
            OutgoingMail::query()->where('person_id', $person->id)->delete();
            $person->households()->detach();
            $person->guardians()->detach();
            $person->wards()->detach();
            $person->groups()->detach();
            $person->forceFill([
                'first_name' => __('privacy.anonymous'), 'last_name' => '#'.$person->id, 'gender' => null,
                'birth_date' => $person->birth_date?->copy()->startOfYear(), 'anonymised_at' => now(),
            ])->save();
            Consent::query()->create(['person_id' => $person->id, 'kind' => 'deletion_done', 'granted' => true, 'source' => 'admin']);
            Audit::record('privacy.anonymised', $person, null, ['reason' => $reason, 'deleted' => $plan['delete'], 'kept' => array_map(fn ($k) => $k['count'], $plan['keep'])]);
        });
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
