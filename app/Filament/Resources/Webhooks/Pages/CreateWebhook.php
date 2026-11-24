<?php

namespace App\Filament\Resources\Webhooks\Pages;

use App\Filament\Resources\Webhooks\WebhookResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class CreateWebhook extends CreateRecord
{
    protected static string $resource = WebhookResource::class;

    private string $secret = '';

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->secret = Str::random(48);

        return $data + ['secret' => $this->secret];
    }

    protected function afterCreate(): void
    {
        Notification::make()->title(__('webhooks.secret_title'))->persistent()->success()
            ->body(new HtmlString(e(__('webhooks.secret_hint')).'<br><code style="user-select:all;word-break:break-all">'.e($this->secret).'</code>'))->send();
    }
}
