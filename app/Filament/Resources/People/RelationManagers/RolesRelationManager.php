<?php

namespace App\Filament\Resources\People\RelationManagers;

use App\Models\Event;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Support\Auth\Permissions;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

/**
 * Roles of a person with period (RO01); only administrators.
 */
class RolesRelationManager extends RelationManager
{
    protected static string $relationship = 'roleAssignments';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('roles.roles');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Permissions::administrator();
    }

    public function isReadOnly(): bool
    {
        return ! Permissions::administrator();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('role_id')->label(__('roles.role'))->required()->options(fn () => Role::query()->get()->mapWithKeys(fn (Role $r) => [$r->id => $r->label()])->all())->live(),
            Select::make('event_id')->label(__('admin.event.singular'))->options(fn () => Event::query()->orderByDesc('starts_at')->limit(50)->pluck('title', 'id')->all())->helperText(__('roles.event_hint'))
                ->visible(fn ($get) => Role::query()->find((int) $get('role_id'))?->ownEventsOnly() === true),
            DatePicker::make('starts_on')->label(__('roles.from'))->default(now()),
            DatePicker::make('ends_on')->label(__('roles.until'))->afterOrEqual('starts_on'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('role.key')->label(__('roles.role'))->formatStateUsing(fn (RoleAssignment $record) => $record->role?->label()),
            TextColumn::make('event.title')->label(__('admin.event.singular'))->placeholder('–'),
            TextColumn::make('starts_on')->label(__('roles.from'))->date()->placeholder('–'),
            TextColumn::make('ends_on')->label(__('roles.until'))->date()->placeholder('–'),
        ])->headerActions([
            CreateAction::make()->label(__('roles.assign'))->visible(fn () => Permissions::administrator()),
        ])->recordActions([
            EditAction::make()->visible(fn () => Permissions::administrator())->using(function (RoleAssignment $record, array $data) {
                $this->guarded(fn () => $record->update($data));

                return $record;
            }),
            DeleteAction::make()->visible(fn () => Permissions::administrator())->using(function (RoleAssignment $record): bool {
                return $this->guarded(fn () => (bool) $record->delete());
            }),
        ]);
    }

    private function guarded(\Closure $change): bool
    {
        try {
            return (bool) $change();
        } catch (AuthorizationException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return false;
        }
    }
}
