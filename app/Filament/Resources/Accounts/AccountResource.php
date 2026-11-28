<?php

namespace App\Filament\Resources\Accounts;

use App\Filament\Resources\Accounts\Pages\ManageAccounts;
use App\Filament\Support\AreaAccess;
use App\Filament\Support\InArea;
use App\Models\Account;
use App\Models\CostCenter;
use App\Support\Accounting\Accounting;
use App\Support\Accounting\Balances;
use App\Support\Accounting\Money;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Chart of accounts (E1): what is booked. System accounts (receivables, open expenses, funds …) keep their type.
 */
class AccountResource extends Resource
{
    use AreaAccess;
    use InArea;

    protected static ?string $model = Account::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    public static function area(): string
    {
        return 'finance';
    }

    public static function getModelLabel(): string
    {
        return __('accounting.account');
    }

    public static function getPluralModelLabel(): string
    {
        return __('accounting.chart');
    }

    public static function canDelete(Model $record): bool
    {
        return parent::canDelete($record) && $record instanceof Account && $record->system_key === null && ! $record->lines()->exists();
    }

    public static function form(Schema $schema): Schema
    {
        $locked = fn (?Account $record) => $record !== null && ($record->system_key !== null || $record->lines()->exists());

        return $schema->components([
            TextInput::make('number')->label(__('accounting.number'))->maxLength(20),
            TextInput::make('name')->label(__('admin.form.name'))->required()->maxLength(120)->unique(ignoreRecord: true),
            Select::make('type')->label(__('accounting.type'))->options(__('accounting.types'))->required()->live()->disabled($locked)->dehydrated()
                ->helperText(fn (?Account $record) => $locked($record) ? __('accounting.type_locked') : null),
            Select::make('money')->label(__('accounting.money'))->options(__('accounting.money_kinds'))->placeholder(__('accounting.money_none'))
                ->visible(fn ($get) => $get('type') === 'asset')->live(),
            TextInput::make('iban')->label('IBAN')->maxLength(42)->visible(fn ($get) => $get('money') === 'bank')
                ->dehydrateStateUsing(fn (?string $state) => $state !== null ? strtoupper(str_replace(' ', '', $state)) : null),
            Select::make('default_cost_center_id')->label(__('accounting.default_cost_center'))->options(fn () => CostCenter::query()->orderBy('name')->pluck('name', 'id')->all())
                ->visible(fn ($get) => Accounting::costCenters() && in_array($get('type'), ['income', 'expense'], true))->helperText(__('accounting.default_cost_center_hint')),
            Toggle::make('active')->label(__('admin.form.active'))->default(true),
            TextInput::make('sort')->label(__('accounting.sort'))->numeric()->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort')->columns([
            TextColumn::make('number')->label(__('accounting.number'))->sortable()->searchable(),
            TextColumn::make('name')->label(__('admin.form.name'))->searchable(),
            TextColumn::make('type')->label(__('accounting.type'))->badge()->formatStateUsing(fn (string $state) => __('accounting.types.'.$state)),
            TextColumn::make('balance')->label(__('accounting.balance_today'))->alignEnd()
                ->state(fn (Account $record) => Money::format(in_array($record->type, ['asset', 'expense'], true)
                    ? app(Balances::class)->account($record, now()) : -app(Balances::class)->account($record, now()))),
            IconColumn::make('active')->label(__('admin.form.active'))->boolean(),
        ])->filters([
            SelectFilter::make('type')->label(__('accounting.type'))->options(__('accounting.types')),
        ])->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAccounts::route('/')];
    }
}
