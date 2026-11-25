<?php

namespace App\Filament\Resources\Groups\RelationManagers;

use App\Models\Group;
use App\Models\Person;
use App\Support\Auth\Permissions;
use App\Support\People\GroupMembers;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Members of a group; members of dynamic and event groups come from their rules.
 */
class PeopleRelationManager extends RelationManager
{
    protected static string $relationship = 'people';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.group.members');
    }

    private function group(): Group
    {
        /** @var Group $group */
        $group = $this->getOwnerRecord();

        return $group;
    }

    private function mayChange(): bool
    {
        return $this->group()->kind === 'manual' && Permissions::current('people', 'edit');
    }

    public function table(Table $table): Table
    {
        return $table->defaultSort('last_name')->columns([
            TextColumn::make('last_name')->label(__('admin.person.singular'))->formatStateUsing(fn (Person $record) => $record->fullName())->searchable(['first_name', 'last_name']),
            TextColumn::make('pivot.starts_on')->label(__('roles.from'))->date()->placeholder('–'),
            TextColumn::make('pivot.source')->label(__('admin.group.source'))->placeholder('–'),
        ])->headerActions([
            Action::make('update')->label(__('admin.group.update'))->icon('heroicon-o-arrow-path')->color('gray')->visible(fn () => $this->group()->kind !== 'manual')
                ->action(fn () => app(GroupMembers::class)->update($this->group())),
            Action::make('add')->label(__('admin.group.add'))->icon('heroicon-o-plus')->visible(fn () => $this->mayChange())
                ->schema([Select::make('person_id')->label(__('admin.person.singular'))->searchable()->required()
                    ->getSearchResultsUsing(fn (string $search) => Person::query()->whereNull('anonymised_at')->where(fn ($q) => $q->where('last_name', 'like', "%{$search}%")->orWhere('first_name', 'like', "%{$search}%"))->limit(30)->get()->mapWithKeys(fn (Person $p) => [$p->id => $p->fullName()])->all())
                    ->getOptionLabelUsing(fn ($value) => Person::query()->whereKey((int) $value)->first()?->fullName())])
                ->action(fn (array $data) => GroupMembers::join($this->group(), (int) $data['person_id'], 'manual')),
        ])->recordActions([
            Action::make('remove')->label(__('admin.group.remove'))->icon('heroicon-o-x-mark')->color('danger')->requiresConfirmation()->visible(fn () => $this->mayChange())
                ->action(fn (Person $record) => GroupMembers::leave($this->group(), $record->id)),
        ], RecordActionsPosition::BeforeColumns);
    }
}
