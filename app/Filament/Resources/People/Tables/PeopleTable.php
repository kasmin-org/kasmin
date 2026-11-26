<?php

namespace App\Filament\Resources\People\Tables;

use App\Filament\Support\PeopleBulkActions;
use App\Models\Event;
use App\Models\MembershipType;
use App\Models\Person;
use App\Models\Role;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\QueryBuilder\Constraints\DateConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint\Operators\IsRelatedToOperator;
use Filament\QueryBuilder\Constraints\SelectConstraint;
use Filament\QueryBuilder\Constraints\TextConstraint;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\QueryBuilder;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class PeopleTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('last_name')
            ->columns([
                TextColumn::make('last_name')->label(__('admin.person.last_name'))->searchable()->sortable(),
                TextColumn::make('first_name')->label(__('admin.person.first_name'))->searchable()->sortable(),
                TextColumn::make('birth_date')->label(__('admin.person.birth_date'))->date()->sortable()->toggleable(),
                TextColumn::make('age')->label(__('admin.person.age'))->state(fn (Person $record) => $record->ageAt()),
                TextColumn::make('households.name')->label(__('admin.household.singular'))->badge()->toggleable(),
            ])
            ->filters([
                Filter::make('children')->label(__('admin.person.filter_children'))
                    ->query(fn (Builder $query) => $query->whereHas('guardians')),
                Filter::make('guardians')->label(__('admin.person.filter_guardians'))
                    ->query(fn (Builder $query) => $query->whereHas('wards')),
                // Age at a date (AD11), e.g. "14 to 15 at the start of the camp".
                Filter::make('age')->schema([
                    TextInput::make('min')->label(__('admin.event.min_age'))->numeric()->minValue(0)->maxValue(120),
                    TextInput::make('max')->label(__('admin.event.max_age'))->numeric()->minValue(0)->maxValue(120),
                    DatePicker::make('at')->label(__('lists.age_at')),
                ])->query(function (Builder $query, array $data) {
                    $at = filled($data['at'] ?? null) ? Carbon::parse((string) $data['at']) : now();
                    if (filled($data['min'] ?? null)) {
                        $query->whereDate('birth_date', '<=', $at->copy()->subYears((int) $data['min'])->toDateString());
                    }
                    if (filled($data['max'] ?? null)) {
                        $query->whereDate('birth_date', '>', $at->copy()->subYears((int) $data['max'] + 1)->toDateString());
                    }
                })->indicateUsing(fn (array $data) => filled($data['min'] ?? null) || filled($data['max'] ?? null) ? __('admin.person.age').': '.($data['min'] ?? '').'–'.($data['max'] ?? '') : null),
                // Any combination with AND, OR and NOT (AD11).
                QueryBuilder::make()->constraints([
                    TextConstraint::make('last_name')->label(__('admin.person.last_name')),
                    TextConstraint::make('first_name')->label(__('admin.person.first_name')),
                    DateConstraint::make('birth_date')->label(__('admin.person.birth_date')),
                    RelationshipConstraint::make('groups')->label(__('admin.group.plural'))->multiple()
                        ->selectable(IsRelatedToOperator::make()->titleAttribute('name')->searchable()->multiple()),
                    RelationshipConstraint::make('households')->label(__('admin.household.plural'))->multiple()
                        ->selectable(IsRelatedToOperator::make()->titleAttribute('name')->searchable()->multiple()),
                    SelectConstraint::make('membership')->label(__('admin.finance.membership_type'))->multiple()
                        ->options(fn () => MembershipType::query()->get()->mapWithKeys(fn (MembershipType $t) => [$t->id => $t->label()])->all())
                        ->relationship('memberships', 'membership_type_id'),
                    SelectConstraint::make('role')->label(__('automations.role'))->multiple()
                        ->options(fn () => Role::query()->get()->mapWithKeys(fn (Role $r) => [$r->id => $r->label()])->all())
                        ->relationship('roleAssignments', 'role_id'),
                    SelectConstraint::make('event')->label(__('admin.event.singular'))->multiple()
                        ->options(fn () => Event::query()->orderByDesc('starts_at')->limit(50)->pluck('title', 'id')->all())
                        ->relationship('participations', 'event_id'),
                    SelectConstraint::make('event_status')->label(__('lists.event_status'))->multiple()
                        ->options(fn () => (array) __('events.status'))
                        ->relationship('participations', 'status'),
                ]),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()])
            ->toolbarActions([BulkActionGroup::make(PeopleBulkActions::make())]);
    }
}
