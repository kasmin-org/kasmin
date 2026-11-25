<?php

namespace App\Support\Mail;

use App\Models\Automation;
use App\Models\Event;
use App\Models\Group;
use App\Models\Household;
use App\Models\Mailing;
use App\Models\Participation;
use App\Models\Person;
use App\Models\User;
use App\Support\Automations\Engine;
use App\Support\People\GroupMembers;
use App\Support\Settings;
use Illuminate\Support\Collection;

/**
 * Mailings (MM01–MM08): recipients from groups, event participants and single persons; routing to legal guardians,
 * the person or both; one mail per family; opt-outs respected; prepared mails are held for review and released.
 */
class Mailings
{
    public function __construct(private readonly Composer $composer, private readonly GroupMembers $groups) {}

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
            foreach (Group::query()->whereIn('id', $r['group_ids'])->get() as $group) {
                $this->groups->update($group);
                $ids = $ids->merge($this->groups->withSubgroups($group));
            }
        }
        if (! empty($r['event_id'])) {
            $ids = $ids->merge(Participation::query()->where('event_id', $r['event_id'])
                ->when(! empty($r['statuses'] ?? []), fn ($q) => $q->whereIn('status', $r['statuses'] ?? []))->pluck('person_id'));
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
        $template = $mailing->template;
        // The latest choice counts: somebody who subscribed again gets the newsletter again (MM04). Opt-outs per event series and
        // per group count for the mailings to exactly these (CO03).
        $optedOut = Optouts::people($template?->category === 'newsletter' ? 'newsletter' : 'optout_all');
        $seriesId = ! empty($mailing->recipients['event_id']) ? Event::query()->whereKey($mailing->recipients['event_id'])->value('event_series_id') : null;
        if ($seriesId !== null) {
            $optedOut = [...$optedOut, ...Optouts::people('optout_series', (int) $seriesId)];
        }
        $groupIds = array_map('intval', $mailing->recipients['group_ids'] ?? []);
        if (count($groupIds) === 1 && empty($mailing->recipients['event_id'])) {
            $optedOut = [...$optedOut, ...Optouts::people('optout_group', $groupIds[0])];
        }
        $private = $this->privateSubjects($mailing);

        foreach ($this->subjects($mailing) as $subject) {
            $targets = match (in_array($subject->id, $private, true) ? 'person' : $mailing->routing) {
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

    /**
     * People who are only recipients because of registrations they made themselves: their legal guardians must not learn of
     * them (PE05a).
     *
     * @return list<int>
     */
    private function privateSubjects(Mailing $mailing): array
    {
        $r = $mailing->recipients;
        if (empty($r['event_id']) || Settings::get('own_registrations') === 'visible') {
            return [];
        }
        $own = Participation::query()->where('event_id', $r['event_id'])->when(! empty($r['statuses'] ?? []), fn ($q) => $q->whereIn('status', $r['statuses'] ?? []))->get()
            ->groupBy('person_id')->filter(fn ($rows) => $rows->every(fn (Participation $p) => $p->isOwn()))->keys()->map(fn ($id) => (int) $id);
        $other = collect($r['person_ids'] ?? []);
        foreach (empty($r['group_ids']) ? [] : Group::query()->whereIn('id', $r['group_ids'])->get() as $group) {
            $other = $other->merge($this->groups->withSubgroups($group));
        }

        return array_values($own->diff($other)->all());
    }

    /** Renders all mails and holds them for review (MM05, MM06). */
    public function prepare(Mailing $mailing): int
    {
        $mailing->mails()->where('status', 'held')->delete();
        $template = $mailing->template;
        if ($template === null) {
            return 0;
        }
        $count = 0;
        foreach ($this->plan($mailing)['mails'] as $mail) {
            $created = $this->composer->queue($template, $mail['to'], ['children' => $mail['children'], 'household' => $mail['household']],
                $template->category === 'newsletter' ? 'newsletter' : 'mailing', 'held', $mailing->id, $this->attachments($mailing) ?: null);
            if ($created !== null && $mailing->sender_profile_id !== null) {
                $created->forceFill(['sender_profile_id' => $mailing->sender_profile_id])->save();
            }
            $count += $created !== null ? 1 : 0;
        }
        $mailing->forceFill(['status' => 'prepared', 'prepared_at' => now()])->save();

        return $count;
    }

    /** @return list<array{file?: string, name?: string, document_id?: int}> */
    private function attachments(Mailing $mailing): array
    {
        $files = array_map(fn (string $path) => ['file' => $path, 'name' => basename($path)], array_values($mailing->attachments ?? []));

        return [...$files, ...array_map(fn ($id) => ['document_id' => (int) $id], array_values($mailing->document_ids ?? []))];
    }

    /** Does this mailing need an approval (MM07)? */
    public function needsApproval(Mailing $mailing): bool
    {
        $limit = Settings::int('mailing_approval_above');

        return $limit > 0 && $mailing->mails()->where('status', 'held')->count() > $limit;
    }

    /** Sends now, or asks for the approval of large mailings. Returns the number of mails, or null while waiting. */
    public function release(Mailing $mailing, ?User $by = null, bool $forceApproval = false): ?int
    {
        $automation = $forceApproval || $this->needsApproval($mailing) ? Automation::query()->where('key', 'mailing_release')->first() : null;
        if ($automation === null && $forceApproval) {
            throw new \RuntimeException('The automation mailing_release is missing.'); // never send without a decision
        }
        if ($automation === null) {
            return $this->send($mailing);
        }
        $mailing->forceFill(['status' => 'waiting'])->save();
        app(Engine::class)->trigger($automation, 'manual', null, null, $by ?? new User, forceApproval: true);

        return null;
    }

    public function send(Mailing $mailing): int
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
