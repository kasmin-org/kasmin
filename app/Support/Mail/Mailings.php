<?php

namespace App\Support\Mail;

use App\Models\Consent;
use App\Models\Group;
use App\Models\Household;
use App\Models\Mailing;
use App\Models\Participation;
use App\Models\Person;
use Illuminate\Support\Collection;

/**
 * Mailings (MM01–MM08): recipients from groups, event participants and single persons; routing to legal guardians,
 * the person or both; one mail per family; opt-outs respected; prepared mails are held for review and released.
 */
class Mailings
{
    public function __construct(private readonly Composer $composer) {}

    /**
     * People the mailing is about (before routing).
     *
     * @return Collection<int, Person>
     */
    public function subjects(Mailing $mailing): Collection
    {
        $r = $mailing->recipients;
        $ids = collect($r['person_ids'] ?? []);
        if (! empty($r['group_ids'])) {
            $ids = $ids->merge(Group::query()->whereIn('id', $r['group_ids'])->with('people')->get()->flatMap(fn (Group $g) => $g->people->pluck('id')));
        }
        if (! empty($r['event_id'])) {
            $ids = $ids->merge(Participation::query()->where('event_id', $r['event_id'])
                ->when(! empty($r['statuses']), fn ($q) => $q->whereIn('status', $r['statuses']))->pluck('person_id'));
        }

        return Person::query()->whereIn('id', $ids->unique())->with(['guardians', 'households'])->get();
    }

    /**
     * Final recipients with what each mail is about; excluded people with the reason (MM04, MM05).
     *
     * @return array{mails: list<array{to: Person, children: list<Person>, household: ?Household}>, excluded: list<array{person: Person, reason: string}>}
     */
    public function plan(Mailing $mailing): array
    {
        $mails = [];
        $excluded = [];
        $optedOut = Consent::query()->where('granted', false)->whereIn('kind', $mailing->template->category === 'newsletter' ? ['newsletter'] : ['optout_all'])->pluck('person_id')->all();

        foreach ($this->subjects($mailing) as $subject) {
            $targets = match ($mailing->routing) {
                'person' => [$subject],
                'both' => array_merge([$subject], $subject->guardians->all()),
                default => $subject->guardians->isNotEmpty() ? $subject->guardians->all() : [$subject],
            };
            foreach ($targets as $target) {
                if (in_array($target->id, $optedOut, true)) {
                    $excluded[] = ['person' => $target, 'reason' => 'opt_out'];

                    continue;
                }
                if ($target->primaryEmail() === null) {
                    $excluded[] = ['person' => $target, 'reason' => 'no_email'];

                    continue;
                }
                $key = $mailing->one_per_family ? 'p'.$target->id : 'p'.$target->id.'-'.$subject->id;
                $mails[$key] ??= ['to' => $target, 'children' => [], 'household' => $subject->households->first()];
                if ($target->id !== $subject->id) {
                    $mails[$key]['children'][] = $subject;
                }
            }
        }

        return ['mails' => array_values($mails), 'excluded' => $excluded];
    }

    /** Renders all mails and holds them for review (MM05, MM06). */
    public function prepare(Mailing $mailing): int
    {
        $mailing->mails()->where('status', 'held')->delete();
        $count = 0;
        foreach ($this->plan($mailing)['mails'] as $mail) {
            $created = $this->composer->queue($mailing->template, $mail['to'], ['children' => $mail['children'], 'household' => $mail['household']],
                $mailing->template->category === 'newsletter' ? 'newsletter' : 'mailing', 'held', $mailing->id);
            if ($created !== null && $mailing->sender_profile_id !== null) {
                $created->forceFill(['sender_profile_id' => $mailing->sender_profile_id])->save();
            }
            $count += $created !== null ? 1 : 0;
        }
        $mailing->forceFill(['status' => 'prepared', 'prepared_at' => now()])->save();

        return $count;
    }

    public function release(Mailing $mailing): int
    {
        $count = $mailing->mails()->where('status', 'held')->update(['status' => 'queued', 'send_after' => $mailing->getAttribute('scheduled_at')]);
        $mailing->forceFill(['status' => 'sending', 'sent_at' => now()])->save();

        return $count;
    }

    public function cancel(Mailing $mailing): void
    {
        $mailing->mails()->whereIn('status', ['held', 'queued'])->update(['status' => 'cancelled']);
        $mailing->forceFill(['status' => 'cancelled'])->save();
    }
}
