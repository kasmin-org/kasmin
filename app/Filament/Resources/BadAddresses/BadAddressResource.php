<?php

namespace App\Filament\Resources\BadAddresses;

use App\Filament\Resources\BadAddresses\Pages\ListBadAddresses;
use App\Filament\Resources\People\PersonResource;
use App\Filament\Support\AreaAccess;
use App\Filament\Support\InArea;
use App\Models\ContactPoint;
use App\Support\Auth\Permissions;
use App\Support\Mail\BadAddresses;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Addresses that cannot receive mails (MS05, M05).
 */
class BadAddressResource extends Resource
{
    use AreaAccess;
    use InArea;

    public static function area(): string
    {
        return 'mailings';
    }

    protected static ?string $model = ContactPoint::class;

    protected static ?string $slug = 'bad-addresses';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    public static function getModelLabel(): string
    {
        return __('admin.mail.bad_address');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.mail.bad_addresses');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /** @return Builder<ContactPoint> */
    public static function getEloquentQuery(): Builder
    {
        return ContactPoint::query()->where('type', 'email')->whereNotNull('bounced_at')->with('person');
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('bounced_at', 'desc')->columns([
            TextColumn::make('person_name')->label(__('lists.name'))->state(fn (ContactPoint $r) => $r->person?->fullName())
                ->url(fn (ContactPoint $r) => $r->person !== null && Permissions::current('people') ? PersonResource::getUrl('view', ['record' => $r->person]) : null),
            TextColumn::make('value')->label(__('lists.email'))->searchable(),
            TextColumn::make('bounced_at')->label(__('lists.date'))->dateTime()->sortable(),
            TextColumn::make('bounce_reason')->label(__('admin.mail.error'))->wrap()->limit(160),
        ])->recordActions([
            Action::make('works')->label(__('admin.mail.address_works'))->icon('heroicon-o-check')->color('gray')->requiresConfirmation()
                ->visible(fn () => Permissions::current('mailings', 'edit'))->action(fn (ContactPoint $r) => app(BadAddresses::class)->clear($r)),
        ], RecordActionsPosition::BeforeColumns)->emptyStateHeading(__('admin.mail.no_bad_addresses'));
    }

    public static function getPages(): array
    {
        return ['index' => ListBadAddresses::route('/')];
    }
}
