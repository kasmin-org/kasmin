<?php

namespace App\Filament\Resources\Consents;

use App\Filament\Resources\Consents\Pages\ListConsents;
use App\Models\Consent;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Consents and opt-outs with date and source (AD73, CO05).
 */
class ConsentResource extends Resource
{
    protected static ?string $model = Consent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHandThumbUp;

    public static function getModelLabel(): string
    {
        return __('privacy.consent');
    }

    public static function getPluralModelLabel(): string
    {
        return __('privacy.consents');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.privacy');
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasAnyRole(['administrator', 'board', 'auditor']);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('created_at')->label(__('privacy.time'))->dateTime()->sortable(),
            TextColumn::make('person.last_name')->label(__('admin.person.singular'))->state(fn (Consent $r) => $r->person?->fullName())->searchable(['first_name', 'last_name']),
            TextColumn::make('kind')->label(__('privacy.kind'))->badge(),
            IconColumn::make('granted')->label(__('privacy.granted'))->boolean(),
            TextColumn::make('source')->label(__('privacy.source'))->toggleable(),
        ])->filters([
            SelectFilter::make('kind')->label(__('privacy.kind'))->options(fn () => Consent::query()->distinct()->pluck('kind', 'kind')->all()),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListConsents::route('/')];
    }
}
