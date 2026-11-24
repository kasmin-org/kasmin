<?php

namespace App\Filament\Resources\Webhooks\Pages;

use App\Filament\Resources\Webhooks\WebhookResource;
use App\Models\Webhook;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class EditWebhook extends EditRecord
{
    protected static string $resource = WebhookResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('secret')->label(__('webhooks.new_secret'))->icon('heroicon-o-key')->requiresConfirmation()
                ->modalDescription(__('webhooks.new_secret_hint'))
                ->action(function () {
                    /** @var Webhook $hook */
                    $hook = $this->getRecord();
                    $secret = Str::random(48);
                    $hook->forceFill(['secret' => $secret])->save();
                    Notification::make()->title(__('webhooks.secret_title'))->persistent()->success()
                        ->body(new HtmlString(e(__('webhooks.secret_hint')).'<br><code style="user-select:all;word-break:break-all">'.e($secret).'</code>'))->send();
                }),
            DeleteAction::make(),
        ];
    }
}
