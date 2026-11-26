<?php

namespace App\Filament\Resources\Roles;

use App\Filament\Resources\Roles\Pages\ManageRoles;
use App\Models\Role;
use App\Support\Auth\Permissions;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Roles and their permissions (AD80, RO04); only administrators.
 */
class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 15;

    public static function getModelLabel(): string
    {
        return __('roles.role');
    }

    public static function getPluralModelLabel(): string
    {
        return __('roles.roles');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.settings');
    }

    public static function canViewAny(): bool
    {
        return Permissions::administrator();
    }

    public static function canCreate(): bool
    {
        return Permissions::administrator();
    }

    public static function canEdit(Model $record): bool
    {
        return Permissions::administrator();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        $admin = fn ($get, ?Role $record) => $record?->key === 'administrator';

        return $schema->components([
            TextInput::make('key')->label(__('admin.form.key'))->required()->alphaDash()->maxLength(40)->unique(ignoreRecord: true)->disabledOn('edit'),
            TextInput::make('name')->label(__('admin.form.name'))->required()->maxLength(80),
            Toggle::make('is_admin')->label(__('roles.is_admin'))->live()->default(true)->disabled($admin),
            Select::make('scope')->label(__('roles.scope'))->options(__('roles.scopes'))->default('all')->required()->visible(fn (?Role $record) => $record?->key !== 'administrator')->hintIcon('heroicon-m-question-mark-circle', tooltip: __('roles.scope_hint')),
            Section::make(__('roles.permissions'))->columns(['md' => 3])->columnSpanFull()->visible(fn ($get, ?Role $record) => (bool) $get('is_admin') && $record?->key !== 'administrator')
                ->schema(array_map(fn (string $area) => Select::make('permissions.'.$area)->label(__('roles.areas.'.$area))->options(__('roles.levels'))->placeholder(__('roles.none'))
                    ->formatStateUsing(fn (?Role $record) => $record !== null ? (Permissions::ofRole($record)[$area] ?? null) : null), Permissions::AREAS)),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->description(count(Permissions::administrators()) < 2 ? (string) __('roles.one_administrator') : null)->columns([
            TextColumn::make('name')->label(__('roles.role'))->state(fn (Role $record) => $record->label()),
            TextColumn::make('scope')->label(__('roles.scope'))->state(fn (Role $record) => __('roles.scopes.'.($record->ownEventsOnly() ? 'own' : 'all'))),
            IconColumn::make('is_admin')->label(__('roles.admin_short'))->boolean(),
            TextColumn::make('permissions')->label(__('roles.permissions'))->wrap()->visibleFrom('md')
                ->state(fn (Role $record) => $record->key === 'administrator' ? __('roles.everything') : implode(', ', array_map(fn (string $area, string $level) => __('roles.areas.'.$area).' ('.__('roles.levels.'.$level).')', array_keys(Permissions::ofRole($record)), Permissions::ofRole($record)))),
        ])->recordActions([EditAction::make()], RecordActionsPosition::BeforeColumns);
    }

    public static function getPages(): array
    {
        return ['index' => ManageRoles::route('/')];
    }
}
