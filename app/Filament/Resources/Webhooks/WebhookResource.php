<?php

namespace App\Filament\Resources\Webhooks;

use App\Filament\Resources\Webhooks\Pages\CreateWebhook;
use App\Filament\Resources\Webhooks\Pages\EditWebhook;
use App\Filament\Resources\Webhooks\Pages\ListWebhooks;
use App\Models\User;
use App\Models\Webhook;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Webhooks (AD81): URL, subscribed events, signing secret (shown once), delivery log.
 */
class WebhookResource extends Resource
{
    protected static ?string $model = Webhook::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTopRightOnSquare;

    public static function getModelLabel(): string
    {
        return __('webhooks.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('webhooks.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.settings');
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasAnyRole(['administrator']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            TextInput::make('name')->label(__('webhooks.name'))->required()->maxLength(120),
            TextInput::make('url')->label('URL')->required()->url()->maxLength(500)->startsWith(['https://'])
                ->helperText(__('webhooks.url_hint')),
            CheckboxList::make('events')->label(__('webhooks.events'))->required()->columns(2)
                ->options(collect(Webhook::EVENTS)->mapWithKeys(fn (string $e) => [$e => $e])->all()),
            Toggle::make('active')->label(__('webhooks.active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label(__('webhooks.name'))->description(fn (Webhook $r) => $r->url),
            TextColumn::make('events')->label(__('webhooks.events'))->badge()->separator(','),
            IconColumn::make('active')->label(__('webhooks.active'))->boolean(),
        ])->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [RelationManagers\DeliveriesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWebhooks::route('/'),
            'create' => CreateWebhook::route('/create'),
            'edit' => EditWebhook::route('/{record}/edit'),
        ];
    }
}
