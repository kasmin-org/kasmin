<?php

namespace App\Support\Import;

use App\Models\Event;
use App\Models\Group;
use App\Models\Household;
use App\Models\ImportBatch;
use App\Models\MembershipType;
use App\Models\Participation;
use App\Models\Person;
use App\Models\User;
use App\Support\Api\ApiContext;
use App\Support\Audit\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Import of people and families from flat rows (MI01–MI06), used by the import wizard, the REST API, MCP and the
 * Webling connector. Every import can run as dry run first; imported records keep their source ID, so the same
 * export can be imported again without duplicates; every import is a batch that can be rolled back as long as
 * nothing was changed afterwards.
 *
 * Row fields (all optional except names): source_id, first_name, last_name, birth_date, gender, locale, email,
 * phone, street, postal_code, city, country, family (explicit family key), groups (comma-separated),
 * membership_type, membership_start, membership_end, guardian1_first_name, guardian1_last_name, guardian1_email,
 * guardian1_phone, guardian2_… (same), participations ("Event title:role:status;…").
 */
class PeopleImporter
{
    public const FIELDS = [
        'source_id', 'first_name', 'last_name', 'birth_date', 'gender', 'locale', 'email', 'phone', 'street', 'postal_code', 'city', 'country',
        'family', 'groups', 'membership_type', 'membership_start', 'membership_end',
        'guardian1_first_name', 'guardian1_last_name', 'guardian1_email', 'guardian1_phone',
        'guardian2_first_name', 'guardian2_last_name', 'guardian2_email', 'guardian2_phone', 'participations',
    ];

    /** @var array<string, Person> people created or found during one run, by guardian key */
    private array $guardians = [];

    /** @var array<string, Household> */
    private array $households = [];

    private ?ImportBatch $batch = null;

    /**
     * Dry run (MI02): what would be created, updated or skipped, with validation errors per row.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{rows: list<array{row: int, action: string, name: string, messages: list<string>}>, summary: array<string, int>, families: list<array<string, mixed>>}
     */
    public function plan(array $rows, string $source, string $locale = 'de'): array
    {
        $result = [];
        $summary = ['create' => 0, 'update' => 0, 'error' => 0];
        foreach ($rows as $i => $raw) {
            $row = $this->normalise($raw, $locale);
            $messages = $this->validate($row);
            if ($messages !== []) {
                $summary['error']++;
                $result[] = ['row' => $i + 1, 'action' => 'error', 'name' => trim(($row['first_name'] ?? '').' '.($row['last_name'] ?? '')), 'messages' => $messages];

                continue;
            }
            $existing = $this->find($row, $source);
            $action = $existing !== null ? 'update' : 'create';
            $summary[$action]++;
            $result[] = ['row' => $i + 1, 'action' => $action, 'name' => $row['first_name'].' '.$row['last_name'], 'messages' => $this->notes($row)];
        }

        return ['rows' => $result, 'summary' => $summary, 'families' => app(FamilyBuilder::class)->suggest($rows)];
    }

    /**
     * Imports the rows as one batch (MI03, MI06). Rows with errors are skipped and reported.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function import(array $rows, string $source, string $locale = 'de'): ImportBatch
    {
        $families = app(FamilyBuilder::class)->familyKeys($rows);

        return DB::transaction(function () use ($rows, $source, $locale, $families) {
            $this->batch = ImportBatch::query()->create([
                'source' => Str::limit($source, 60, ''), 'kind' => 'people', 'status' => 'imported',
                'created_by_id' => auth()->id(), 'api_token_id' => ApiContext::token()?->id,
            ]);
            $this->guardians = [];
            $this->households = [];
            $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
            foreach ($rows as $i => $raw) {
                $row = $this->normalise($raw, $locale);
                $messages = $this->validate($row);
                if ($messages !== []) {
                    $stats['skipped']++;
                    $stats['errors'][] = ['row' => $i + 1, 'messages' => $messages];

                    continue;
                }
                $row['family'] = $row['family'] ?? ($families[$i] ?? null);
                $created = $this->importRow($row, $source);
                $stats[$created ? 'created' : 'updated']++;
            }
            $this->batch->forceFill(['stats' => $stats])->save();
            Audit::record('import.batch_imported', $this->batch, null, ['source' => $source, 'created' => $stats['created'], 'updated' => $stats['updated'], 'skipped' => $stats['skipped']]);

            return $this->batch;
        });
    }

    /**
     * Rolls a batch back (MI06): created records are deleted, updated ones restored – only if none of them was
     * changed after the import.
     */
    public function rollback(ImportBatch $batch): void
    {
        if ($batch->status !== 'imported') {
            throw new RuntimeException('This batch was already rolled back.');
        }
        $records = DB::table('import_records')->where('import_batch_id', $batch->id)->orderByDesc('id')->get();
        foreach ($records as $record) {
            $model = $this->model((string) $record->model_type, (int) $record->model_id);
            $updated = $model?->getAttribute('updated_at');
            if ($updated instanceof Carbon && $updated->gt($batch->updated_at?->copy()->addSeconds(5) ?? now())) {
                throw new RuntimeException('Record '.class_basename((string) $record->model_type).' #'.$record->model_id.' was changed after the import; roll back by hand.');
            }
        }
        DB::transaction(function () use ($batch, $records) {
            foreach ($records as $record) {
                $model = $this->model((string) $record->model_type, (int) $record->model_id);
                if ($model === null) {
                    continue;
                }
                if ($record->action === 'created') {
                    $model->delete();
                } elseif ($record->before !== null) {
                    $model->timestamps = false; // keep the restored updated_at, so older batches can still be rolled back
                    $model->forceFill((array) json_decode((string) $record->before, true))->saveQuietly();
                }
            }
            DB::table('external_ids')->whereIn('id', DB::table('import_records')->where('import_batch_id', $batch->id)->where('model_type', 'external_id')->pluck('model_id'))->delete();
            $batch->forceFill(['status' => 'rolled_back', 'rolled_back_at' => now()])->save();
            Audit::record('import.batch_rolled_back', $batch);
        });
    }

    /** @param  array<string, mixed>  $row */
    private function importRow(array $row, string $source): bool
    {
        $person = $this->find($row, $source);
        $created = $person === null;
        $attributes = array_filter([
            'first_name' => $row['first_name'], 'last_name' => $row['last_name'], 'birth_date' => $row['birth_date'] ?? null,
            'gender' => $row['gender'] ?? null, 'locale' => $row['locale'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
        if ($person === null) {
            $person = Person::query()->create($attributes);
            $this->recordCreated($person);
        } else {
            $this->recordUpdated($person);
            $person->fill($attributes)->save();
        }
        if (! empty($row['source_id'])) {
            $this->externalId($source, (string) $row['source_id'], $person);
        }

        $guardians = [];
        foreach ([1, 2] as $n) {
            if (! empty($row["guardian{$n}_first_name"]) || ! empty($row["guardian{$n}_email"])) {
                $guardians[] = $this->guardian($row, $n);
            }
        }
        $isChild = $guardians !== [];
        $this->contacts($person, $isChild ? null : ($row['email'] ?? null), $row['phone'] ?? null, $isChild ? ['emergency'] : ['login', 'information', 'invoices']);
        if (! $isChild && ! empty($row['email'])) {
            $this->user($person, (string) $row['email']);
        }
        foreach ($guardians as $guardian) {
            if (! $person->guardians()->whereKey($guardian->id)->exists()) {
                $person->guardians()->attach($guardian->id);
            }
        }
        if ($isChild || ! empty($row['family'])) {
            $household = $this->household($row, $guardians[0] ?? $person);
            $this->member($household, $person, $isChild ? 'child' : 'guardian');
            foreach ($guardians as $guardian) {
                $this->member($household, $guardian, 'guardian');
            }
            if ($household->getAttribute('invoice_recipient_id') === null && isset($guardians[0])) {
                $household->forceFill(['invoice_recipient_id' => $guardians[0]->id])->save();
            }
        }
        $this->groups($person, (string) ($row['groups'] ?? ''));
        $this->membership($person, $row);
        $this->participations($person, (string) ($row['participations'] ?? ''));

        return $created;
    }

    /** @param  array<string, mixed>  $row */
    private function guardian(array $row, int $n): Person
    {
        $first = trim((string) ($row["guardian{$n}_first_name"] ?? ''));
        $last = trim((string) ($row["guardian{$n}_last_name"] ?? '')) ?: (string) $row['last_name'];
        $email = Str::lower(trim((string) ($row["guardian{$n}_email"] ?? '')));
        $key = $email !== '' ? 'mail:'.$email : 'name:'.Str::lower($first.'|'.$last.'|'.($row['family'] ?? ''));
        if (isset($this->guardians[$key])) {
            return $this->guardians[$key];
        }
        $person = null;
        if ($email !== '') {
            $person = Person::query()->whereHas('contactPoints', fn ($q) => $q->where('type', 'email')->where('value', $email))
                ->when($first !== '', fn ($q) => $q->where('first_name', $first))->first();
        }
        if ($person === null) {
            $person = Person::query()->create(['first_name' => $first !== '' ? $first : '?', 'last_name' => $last, 'locale' => $row['locale'] ?? 'de']);
            $this->recordCreated($person);
        }
        $this->contacts($person, $email ?: null, $row["guardian{$n}_phone"] ?? null, ['login', 'information', 'invoices']);
        if ($email !== '') {
            $this->user($person, $email);
        }

        return $this->guardians[$key] = $person;
    }

    /** @param  array<string, mixed>  $row */
    private function household(array $row, Person $anchor): Household
    {
        $key = (string) ($row['family'] ?? 'person:'.$anchor->id);
        if (isset($this->households[$key])) {
            return $this->households[$key];
        }
        $household = $anchor->households()->first();
        if ($household === null) {
            $household = Household::query()->create([
                'name' => $anchor->last_name, 'street' => $row['street'] ?? null, 'postal_code' => $row['postal_code'] ?? null,
                'city' => $row['city'] ?? null, 'country' => $row['country'] ?? 'AT', 'locale' => $row['locale'] ?? 'de',
            ]);
            $this->recordCreated($household);
        }

        return $this->households[$key] = $household;
    }

    private function member(Household $household, Person $person, string $role): void
    {
        if (! $household->members()->whereKey($person->id)->exists()) {
            $household->members()->attach($person->id, ['role' => $role]);
        }
    }

    /** @param  list<string>  $purposes */
    private function contacts(Person $person, mixed $email, mixed $phone, array $purposes): void
    {
        foreach (['email' => Str::lower(trim((string) $email)), 'phone' => trim((string) $phone)] as $type => $value) {
            if ($value === '' || $person->contactPoints()->where('type', $type)->where('value', $value)->exists()) {
                continue;
            }
            $contact = $person->contactPoints()->create([
                'type' => $type, 'value' => $value, 'purposes' => $type === 'phone' ? array_values(array_unique(array_merge($purposes, ['emergency']))) : $purposes,
                'is_primary' => ! $person->contactPoints()->where('type', $type)->exists(),
            ]);
            $this->recordCreated($contact);
        }
    }

    private function user(Person $person, string $email): void
    {
        $email = Str::lower($email);
        if ($person->user !== null || User::query()->where('email', $email)->exists()) {
            return;
        }
        $user = User::query()->create(['name' => $person->fullName(), 'email' => $email, 'password' => Str::password(64), 'person_id' => $person->id]);
        $this->recordCreated($user);
    }

    private function groups(Person $person, string $names): void
    {
        foreach (array_filter(array_map('trim', explode(',', $names))) as $name) {
            $group = Group::query()->firstOrCreate(['name' => $name], ['kind' => 'manual']);
            if ($group->wasRecentlyCreated) {
                $this->recordCreated($group);
            }
            if (! $person->groups()->whereKey($group->id)->exists()) {
                $person->groups()->attach($group->id, ['starts_on' => now()->toDateString(), 'source' => 'import']);
            }
        }
    }

    /** @param  array<string, mixed>  $row */
    private function membership(Person $person, array $row): void
    {
        $type = ! empty($row['membership_type']) ? MembershipType::query()->where('key', (string) $row['membership_type'])->first() : null;
        if ($type === null || $person->memberships()->where('membership_type_id', $type->id)->exists()) {
            return;
        }
        $membership = $person->memberships()->create([
            'membership_type_id' => $type->id, 'starts_on' => $row['membership_start'] ?? now()->toDateString(), 'ends_on' => $row['membership_end'] ?? null,
        ]);
        $this->recordCreated($membership);
    }

    /** Historical participations "Event title:role:status;…" (MI05); the event must exist. */
    private function participations(Person $person, string $value): void
    {
        foreach (array_filter(array_map('trim', explode(';', $value))) as $entry) {
            [$title, $role, $status] = array_pad(array_map('trim', explode(':', $entry)), 3, null);
            $event = Event::query()->where('title', $title)->first();
            if ($event === null || Participation::query()->where('event_id', $event->id)->where('person_id', $person->id)->exists()) {
                continue;
            }
            $participation = Participation::query()->create([
                'event_id' => $event->id, 'person_id' => $person->id, 'role_key' => $role ?: 'child',
                'status' => in_array($status, Participation::STATUSES, true) ? $status : 'attended', 'registered_at' => $event->starts_at,
            ]);
            $this->recordCreated($participation);
        }
    }

    /** @param  array<string, mixed>  $row */
    private function find(array $row, string $source): ?Person
    {
        if (! empty($row['source_id'])) {
            $id = DB::table('external_ids')->where('source', $source)->where('source_id', (string) $row['source_id'])->where('model_type', (new Person)->getMorphClass())->value('model_id');
            if ($id !== null) {
                return Person::query()->find((int) $id);
            }
        }
        if (! empty($row['birth_date'])) {
            return Person::query()->where('first_name', $row['first_name'])->where('last_name', $row['last_name'])->whereDate('birth_date', (string) $row['birth_date'])->first();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function normalise(array $raw, string $locale): array
    {
        $row = [];
        foreach (self::FIELDS as $field) {
            $value = $raw[$field] ?? null;
            $row[$field] = is_string($value) ? trim($value) : $value;
        }
        foreach (['birth_date', 'membership_start', 'membership_end'] as $field) {
            if (! empty($row[$field])) {
                $row[$field] = $this->date((string) $row[$field]) ?? $row[$field];
            }
        }
        $row['locale'] = in_array($row['locale'] ?? null, ['de', 'en'], true) ? $row['locale'] : $locale;
        $row['email'] = ! empty($row['email']) ? Str::lower((string) $row['email']) : null;

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function validate(array $row): array
    {
        $messages = [];
        if (empty($row['first_name']) || empty($row['last_name'])) {
            $messages[] = 'first_name and last_name are required';
        }
        foreach (['birth_date', 'membership_start', 'membership_end'] as $field) {
            if (! empty($row[$field]) && $this->date((string) $row[$field]) === null) {
                $messages[] = "{$field} is not a date";
            }
        }
        foreach (['email', 'guardian1_email', 'guardian2_email'] as $field) {
            if (! empty($row[$field]) && filter_var($row[$field], FILTER_VALIDATE_EMAIL) === false) {
                $messages[] = "{$field} is not an e-mail address";
            }
        }
        if (! empty($row['membership_type']) && ! MembershipType::query()->where('key', (string) $row['membership_type'])->exists()) {
            $messages[] = 'unknown membership_type '.$row['membership_type'];
        }

        return $messages;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function notes(array $row): array
    {
        $notes = [];
        foreach (array_filter(array_map('trim', explode(';', (string) ($row['participations'] ?? '')))) as $entry) {
            $title = trim(explode(':', $entry)[0]);
            if (! Event::query()->where('title', $title)->exists()) {
                $notes[] = "event \"{$title}\" not found – participation skipped";
            }
        }

        return $notes;
    }

    private function date(string $value): ?string
    {
        foreach (['Y-m-d', 'd.m.Y', 'j.n.Y', 'd/m/Y'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $value);
            } catch (\Throwable) {
                continue;
            }
            if ($date !== null && $date->format($format) === $value) {
                return $date->toDateString();
            }
        }

        return null;
    }

    private function externalId(string $source, string $sourceId, Person $person): void
    {
        $exists = DB::table('external_ids')->where('source', $source)->where('source_id', $sourceId)->where('model_type', $person->getMorphClass())->exists();
        if ($exists) {
            return;
        }
        $id = DB::table('external_ids')->insertGetId(['source' => $source, 'source_id' => $sourceId, 'model_type' => $person->getMorphClass(), 'model_id' => $person->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('import_records')->insert(['import_batch_id' => $this->batch?->id, 'model_type' => 'external_id', 'model_id' => $id, 'action' => 'created']);
    }

    private function recordCreated(Model $model): void
    {
        DB::table('import_records')->insert(['import_batch_id' => $this->batch?->id, 'model_type' => $model::class, 'model_id' => $model->getKey(), 'action' => 'created']);
    }

    private function recordUpdated(Model $model): void
    {
        DB::table('import_records')->insert(['import_batch_id' => $this->batch?->id, 'model_type' => $model::class, 'model_id' => $model->getKey(), 'action' => 'updated', 'before' => json_encode($model->getAttributes())]);
    }

    private function model(string $type, int $id): ?Model
    {
        if (! class_exists($type) || ! is_subclass_of($type, Model::class)) {
            return null;
        }

        return $type::query()->find($id);
    }
}
