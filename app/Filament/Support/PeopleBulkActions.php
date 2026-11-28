<?php

namespace App\Filament\Support;

use App\Filament\Resources\Mailings\MailingResource;
use App\Models\Group;
use App\Models\Mailing;
use App\Models\MailTemplate;
use App\Models\Participation;
use App\Models\Person;
use App\Support\Audit\Audit;
use App\Support\Auth\Permissions;
use Closure;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Actions on selected rows of tables with people (AD12): groups and mailings. A mailing made here is a draft –
 * preview, approval and sending follow the normal way.
 */
class PeopleBulkActions
{
    /**
     * @param  Closure(Collection<int, Model>): list<int>|null  $ids  person ids of the selected rows (default: the rows are people)
     * @return list<BulkAction>
     */
    public static function make(?Closure $ids = null): array
    {
        $ids ??= fn (Collection $records) => array_values($records->modelKeys());
        $manual = fn () => Group::query()->where('kind', 'manual')->orderBy('name')->pluck('name', 'id')->all();

        return [
            BulkAction::make('add_to_group')->label(__('admin.bulk.add_to_group'))->icon('heroicon-o-user-plus')
                ->visible(fn () => Permissions::current('people', 'edit'))
                ->schema([Select::make('group_id')->label(__('admin.group.singular'))->options($manual)->required()->helperText(__('admin.bulk.manual_only'))])
                ->action(function (Collection $records, array $data) use ($ids) {
                    $added = self::addToGroup((int) $data['group_id'], $ids($records));
                    Notification::make()->title(trans_choice('admin.bulk.added', $added, ['count' => $added]))->success()->send();
                })->deselectRecordsAfterCompletion(),
            BulkAction::make('remove_from_group')->label(__('admin.bulk.remove_from_group'))->icon('heroicon-o-user-minus')
                ->visible(fn () => Permissions::current('people', 'edit'))
                ->schema([Select::make('group_id')->label(__('admin.group.singular'))->options($manual)->required()])
                ->action(function (Collection $records, array $data) use ($ids) {
                    $removed = self::removeFromGroup((int) $data['group_id'], $ids($records));
                    Notification::make()->title(trans_choice('admin.bulk.removed', $removed, ['count' => $removed]))->success()->send();
                })->deselectRecordsAfterCompletion(),
            BulkAction::make('mailing')->label(__('admin.bulk.mailing'))->icon('heroicon-o-envelope')
                ->visible(fn () => Permissions::current('mailings', 'edit'))
                ->modalDescription(__('admin.bulk.mailing_hint'))
                ->schema([
                    TextInput::make('name')->label(__('admin.mail.name'))->required()->maxLength(120)->default(fn () => __('admin.bulk.selection', ['date' => now()->isoFormat('L')])),
                    Select::make('mail_template_id')->label(__('admin.mail.template'))->options(fn () => MailTemplate::query()->get()->mapWithKeys(fn (MailTemplate $t) => [$t->id => $t->label()])->sort(SORT_NATURAL | SORT_FLAG_CASE)->all())->searchable()->required(),
                    Select::make('routing')->label(__('admin.mail.routing'))->options(__('admin.mail.audiences'))->default('guardians')->required(),
                ])
                ->action(function (Collection $records, array $data) use ($ids) {
                    $mailing = self::mailing((string) $data['name'], (int) $data['mail_template_id'], (string) $data['routing'], $ids($records));

                    return redirect(MailingResource::getUrl('edit', ['record' => $mailing]));
                }),
        ];
    }

    /** @param  array<array-key, int|string>  $personIds */
    public static function addToGroup(int $groupId, array $personIds): int
    {
        $group = Group::query()->where('kind', 'manual')->findOrFail($groupId);
        $added = 0;
        foreach (Person::query()->whereKey($personIds)->whereNull('anonymised_at')->get() as $person) {
            if (! $person->groups()->whereKey($group->id)->exists()) {
                $person->groups()->attach($group->id, ['starts_on' => now()->toDateString(), 'source' => 'bulk']);
                $added++;
            }
        }
        Audit::record('group.members_added', $group, meta: ['count' => $added]);

        return $added;
    }

    /** @param  array<array-key, int|string>  $personIds */
    public static function removeFromGroup(int $groupId, array $personIds): int
    {
        $group = Group::query()->where('kind', 'manual')->findOrFail($groupId);
        $removed = DB::table('group_memberships')->where('group_id', $group->id)->whereIn('person_id', $personIds)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', now()->toDateString()))
            ->update(['ends_on' => now()->subDay()->toDateString(), 'updated_at' => now()]);
        Audit::record('group.members_removed', $group, meta: ['count' => $removed]);

        return $removed;
    }

    /** @param  array<array-key, int|string>  $personIds */
    public static function mailing(string $name, int $templateId, string $routing, array $personIds): Mailing
    {
        return Mailing::query()->create([
            'name' => $name, 'mail_template_id' => $templateId, 'routing' => $routing, 'status' => 'draft',
            'recipients' => ['person_ids' => Person::query()->whereKey($personIds)->whereNull('anonymised_at')->pluck('id')->all()],
        ]);
    }

    /** Person ids of selected registrations. */
    public static function ofParticipations(): Closure
    {
        return fn (Collection $records) => array_values($records->map(fn ($p) => $p instanceof Participation ? $p->person_id : null)->filter()->unique()->all());
    }
}
