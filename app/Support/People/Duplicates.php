<?php

namespace App\Support\People;

use App\Models\Person;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Auth\Permissions;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * People that are probably the same (PE10, RG04), and merging them (AD23). Nothing is ever merged automatically.
 */
class Duplicates
{
    public const FIELDS = ['first_name', 'last_name', 'birth_date', 'gender'];

    /**
     * @return list<array{first: Person, second: Person, reason: string}>
     */
    public function all(int $limit = 100): array
    {
        $people = Person::query()->whereNull('anonymised_at')->with('contactPoints')->get();
        $dismissed = DB::table('duplicate_dismissals')->get()->mapWithKeys(fn ($d) => [$d->first_id.':'.$d->second_id => true])->all();
        $buckets = [];
        foreach ($people as $person) {
            foreach ($this->keys($person) as $key) {
                $buckets[$key][] = $person;
            }
        }
        $pairs = [];
        foreach ($buckets as $bucket) {
            foreach ($bucket as $i => $a) {
                foreach (array_slice($bucket, $i + 1) as $b) {
                    [$first, $second] = $a->id < $b->id ? [$a, $b] : [$b, $a];
                    $key = $first->id.':'.$second->id;
                    $reason = isset($pairs[$key]) || isset($dismissed[$key]) ? null : $this->reason($first, $second);
                    if ($reason !== null) {
                        $pairs[$key] = ['first' => $first, 'second' => $second, 'reason' => $reason];
                    }
                }
            }
        }

        return array_slice(array_values($pairs), 0, $limit);
    }

    /** @return list<Person> */
    public function of(Person $person): array
    {
        $others = [];
        foreach ($this->all(1000) as $pair) {
            if ($pair['first']->is($person) || $pair['second']->is($person)) {
                $others[] = $pair['first']->is($person) ? $pair['second'] : $pair['first'];
            }
        }

        return $others;
    }

    public function dismiss(Person $a, Person $b): void
    {
        DB::table('duplicate_dismissals')->insertOrIgnore(['first_id' => min($a->id, $b->id), 'second_id' => max($a->id, $b->id), 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Moves everything from $duplicate to $keep and deletes $duplicate. $fields: field => value to keep.
     *
     * @param  array<string, mixed>  $fields
     */
    public function merge(Person $keep, Person $duplicate, array $fields = []): void
    {
        if ($keep->is($duplicate)) {
            throw new RuntimeException('Same person');
        }
        if ($duplicate->activeRoleKeys() !== [] && array_diff($duplicate->activeRoleKeys(), $keep->activeRoleKeys()) !== []) {
            throw new RuntimeException(__('people.merge_roles')); // a merge must never give somebody more rights
        }
        // The addresses of the duplicate would become logins of the kept person.
        $user = Auth::user();
        if ($user instanceof User && ! $user->hasAnyRole(['administrator']) && (Permissions::administrative($keep) || Permissions::administrative($duplicate))) {
            throw new RuntimeException(__('roles.contact_protected'));
        }
        $both = DB::table('participations as a')->join('participations as b', fn ($j) => $j->on('a.event_id', 'b.event_id')->on('a.role_key', 'b.role_key'))
            ->where('a.person_id', $keep->id)->where('b.person_id', $duplicate->id)->pluck('b.id');
        if ($both->isNotEmpty() && DB::table('invoice_lines')->whereIn('participation_id', $both)->exists()) {
            throw new RuntimeException(__('people.merge_invoiced'));
        }
        DB::transaction(function () use ($keep, $duplicate, $fields) {
            $snapshot = array_intersect_key($duplicate->getAttributes(), array_flip([...self::FIELDS, 'id', 'public_id']));
            // One login per person: the kept person's, otherwise the duplicate's.
            $user = User::query()->where('person_id', $duplicate->id)->first();
            if ($user !== null) {
                User::query()->where('person_id', $keep->id)->exists() ? $user->delete() : $user->forceFill(['person_id' => $keep->id])->save();
            }
            foreach (Schema::getTableListing(Schema::getCurrentSchemaName(), schemaQualified: false) as $table) {
                foreach (Schema::getForeignKeys($table) as $fk) {
                    if ($fk['foreign_table'] === 'people' && count($fk['columns']) === 1) {
                        $this->move($table, $fk['columns'][0], $duplicate->id, $keep->id);
                    }
                }
            }
            foreach (['external_ids' => 'model_id', 'import_records' => 'model_id'] as $table => $column) {
                foreach (DB::table($table)->where('model_type', $duplicate->getMorphClass())->where($column, $duplicate->id)->pluck('id') as $id) {
                    $this->update($table, $column, (int) $id, $keep->id);
                }
            }
            DB::table('guardianships')->whereColumn('guardian_id', 'child_id')->delete();
            $seen = [];
            foreach ($keep->contactPoints()->orderByDesc('is_primary')->orderBy('id')->get() as $point) {
                $key = $point->type.':'.Str::lower((string) $point->value);
                $primary = $point->type.':primary';
                if (isset($seen[$key])) {
                    $point->delete();

                    continue;
                }
                if ($point->is_primary && isset($seen[$primary])) {
                    $point->update(['is_primary' => false]);
                }
                $seen[$key] = true;
                $seen[$primary] = $seen[$primary] ?? (bool) $point->is_primary;
            }
            $keep->fill(array_intersect_key($fields, array_flip(self::FIELDS)))->save();
            $duplicate->refresh();
            $duplicate->delete();
            Audit::record('person.merged', $keep, ['merged' => $snapshot]);
        });
    }

    private function move(string $table, string $column, int $from, int $to): void
    {
        if (! Schema::hasColumn($table, 'id')) {
            DB::table($table)->where($column, $from)->update([$column => $to]);

            return;
        }
        foreach (DB::table($table)->where($column, $from)->pluck('id') as $id) {
            $this->update($table, $column, (int) $id, $to);
        }
    }

    /** A row that exists for both people stays at the duplicate and is deleted with it. */
    private function update(string $table, string $column, int $id, int $to): void
    {
        try {
            DB::table($table)->where('id', $id)->update([$column => $to]);
        } catch (QueryException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
        }
    }

    /** @return list<string> people sharing a key are compared */
    private function keys(Person $person): array
    {
        $keys = [];
        foreach ($person->contactPoints->where('type', 'email') as $point) {
            $keys[] = 'mail:'.Str::lower((string) $point->value);
        }
        $keys[] = 'name:'.$this->plain($person->fullName());
        if ($person->birth_date !== null) {
            $keys[] = 'born:'.$person->birth_date->toDateString();
        }

        return $keys;
    }

    private function reason(Person $a, Person $b): ?string
    {
        $sameBirth = $a->birth_date !== null && $b->birth_date !== null && $a->birth_date->isSameDay($b->birth_date);
        $otherBirth = $a->birth_date !== null && $b->birth_date !== null && ! $sameBirth;
        if ($this->plain($a->fullName()) === $this->plain($b->fullName())) {
            return $otherBirth ? null : ($sameBirth ? 'name_birth' : 'name');
        }
        $sounds = $this->sound($a->first_name) === $this->sound($b->first_name) && $this->sound($a->last_name) === $this->sound($b->last_name);
        if ($sounds && $sameBirth) {
            return 'sound_birth';
        }
        $mails = fn (Person $p) => $p->contactPoints->where('type', 'email')->map(fn ($c) => Str::lower((string) $c->value))->all();

        // Families share addresses, so the first names must sound alike too.
        return ! $otherBirth && array_intersect($mails($a), $mails($b)) !== [] && $this->sound($a->first_name) === $this->sound($b->first_name) ? 'mail' : null;
    }

    private function plain(string $text): string
    {
        return trim((string) preg_replace('/[^a-z]+/', ' ', Str::lower(Str::ascii($text))));
    }

    private function sound(string $text): string
    {
        $text = str_replace(' ', '', $this->plain($text));

        return (string) metaphone(strtr($text, ['ph' => 'f', 'dt' => 't', 'ck' => 'k', 'tz' => 'z', 'ey' => 'ei', 'ai' => 'ei', 'ay' => 'ei', 'th' => 't']));
    }
}
