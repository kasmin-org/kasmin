<?php

namespace App\Filament\Resources\ApiTokens;

use App\Filament\Resources\ApiTokens\Pages\ListApiTokens;
use App\Models\ApiToken;
use App\Models\User;
use App\Support\Audit\Audit;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * API and MCP tokens (AD81, X03): create with scopes and expiry (the token is shown once), revoke, last use.
 */
class ApiTokenResource extends Resource
{
    protected static ?string $model = ApiToken::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    public static function getModelLabel(): string
    {
        return __('api.token');
    }

    public static function getPluralModelLabel(): string
    {
        return __('api.tokens');
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

    public static function canCreate(): bool
    {
        return false; // created with the header action, which shows the token once
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('name')->label(__('api.name'))->description(fn (ApiToken $r) => $r->prefix.'…'),
                TextColumn::make('user.name')->label(__('api.user')),
                TextColumn::make('scopes')->label(__('api.scopes'))->badge()->separator(','),
                IconColumn::make('mcp')->label('MCP')->boolean(),
                TextColumn::make('last_used_at')->label(__('api.last_used'))->since()->placeholder('–'),
                TextColumn::make('expires_at')->label(__('api.expires'))->date()->placeholder(__('api.never')),
                TextColumn::make('revoked_at')->label(__('api.revoked'))->dateTime()->placeholder('–')->toggleable(),
            ])
            ->recordActions([
                Action::make('revoke')->label(__('api.revoke'))->icon('heroicon-o-no-symbol')->color('danger')->requiresConfirmation()
                    ->visible(fn (ApiToken $r) => $r->revoked_at === null)
                    ->action(function (ApiToken $r) {
                        $r->forceFill(['revoked_at' => now()])->save();
                        Audit::record('api.token_revoked', $r, null, ['name' => $r->name]);
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListApiTokens::route('/')];
    }
}
