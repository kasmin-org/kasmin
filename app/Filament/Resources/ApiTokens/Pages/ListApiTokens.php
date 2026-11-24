<?php

namespace App\Filament\Resources\ApiTokens\Pages;

use App\Filament\Resources\ApiTokens\ApiTokenResource;
use App\Models\ApiToken;
use App\Models\User;
use App\Support\Audit\Audit;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

class ListApiTokens extends ListRecords
{
    protected static string $resource = ApiTokenResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')->label(__('api.create'))->icon('heroicon-o-plus')
                ->modalDescription(__('api.create_hint'))
                ->schema([
                    TextInput::make('name')->label(__('api.name'))->required()->maxLength(120),
                    CheckboxList::make('scopes')->label(__('api.scopes'))->required()->columns(2)
                        ->options(collect(ApiToken::SCOPES)->mapWithKeys(fn (string $s) => [$s => $s.' – '.__('api.scope_help.'.$s)])->all()),
                    Toggle::make('mcp')->label(__('api.mcp'))->helperText(__('api.mcp_hint')),
                    DatePicker::make('expires_at')->label(__('api.expires'))->minDate(now()->addDay())->default(now()->addYear()),
                ])
                ->action(function (array $data) {
                    /** @var User $user */
                    $user = auth()->user();
                    ['token' => $token, 'plain' => $plain] = ApiToken::issue($user, (string) $data['name'], array_values($data['scopes']), (bool) ($data['mcp'] ?? false),
                        ! empty($data['expires_at']) ? Carbon::parse((string) $data['expires_at'])->endOfDay() : null);
                    Audit::record('api.token_created', $token, null, ['name' => $token->name, 'scopes' => $token->scopes, 'mcp' => $token->mcp]);
                    Notification::make()->title(__('api.created'))->persistent()->success()
                        ->body(new HtmlString(e(__('api.created_hint')).'<br><code style="user-select:all;word-break:break-all">'.e($plain).'</code>'))->send();
                }),
        ];
    }
}
