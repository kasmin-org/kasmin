<?php

namespace App\Filament\Resources\MembershipTypes;

use App\Filament\Resources\MembershipTypes\Pages\ManageMembershipTypes;
use App\Filament\Support\Money;
use App\Models\Invoice;
use App\Models\MembershipType;
use App\Models\User;
use App\Support\Auth\Permissions;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Membership types with their yearly fee (PE, PR04).
 */
class MembershipTypeResource extends Resource
{
    protected static ?string $model = MembershipType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?int $navigationSort = 60;

    public static function getModelLabel(): string
    {
        return __('admin.finance.membership_type');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.finance.membership_types');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.people');
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && (Permissions::allows($user, 'people', 'edit') || Permissions::allows($user, 'finance', 'edit'));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('key')->label(__('admin.finance.installment_key'))->required()->alphaDash()->maxLength(64)->unique(ignoreRecord: true)->disabledOn('edit'),
            TextInput::make('name.de')->label(__('admin.finance.label_de'))->required(),
            TextInput::make('name.en')->label(__('admin.finance.label_en'))->required(),
            Toggle::make('pays_fee')->label(__('admin.finance.pays_fee'))->live(),
            Money::input('fee_cents', __('admin.finance.yearly_fee'))->visible(fn ($get) => (bool) $get('pays_fee')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort')->columns([
            TextColumn::make('name')->label(__('admin.finance.membership_type'))->state(fn (MembershipType $record) => $record->label()),
            IconColumn::make('pays_fee')->label(__('admin.finance.pays_fee'))->boolean(),
            TextColumn::make('fee_cents')->label(__('admin.finance.yearly_fee'))->alignEnd()->formatStateUsing(fn ($state) => Invoice::money((int) $state)),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageMembershipTypes::route('/')];
    }
}
