<?php

namespace App\Filament\Resources\MailAccounts\Pages;

use App\Filament\Resources\MailAccounts\MailAccountResource;
use App\Models\MailAccount;
use App\Support\Mail\Sender;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Throwable;

class EditMailAccount extends EditRecord
{
    use SavesSender;

    protected static string $resource = MailAccountResource::class;

    public function getTitle(): string
    {
        return __('admin.mail.sending');
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->withSender($data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->takeSender($data);
    }

    protected function afterSave(): void
    {
        $this->storeSender($this->account());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test')->label(__('admin.mail.test'))->icon('heroicon-o-paper-airplane')
                ->schema([TextInput::make('to')->label(__('admin.mail.test_to'))->email()->required()->default(fn () => auth()->user()?->getAttribute('email'))])
                ->action(function (array $data) {
                    try {
                        app(Sender::class)->test($this->account(), (string) $data['to']);
                        Notification::make()->title(__('admin.mail.test_ok'))->success()->send();
                    } catch (Throwable $e) {
                        Notification::make()->title(__('admin.mail.test_failed'))->body($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('dkim')->label(__('admin.mail.dkim_generate'))->icon('heroicon-o-key')->color('gray')->requiresConfirmation()
                ->modalDescription(__('admin.mail.dkim_generate_hint'))
                ->action(function () {
                    $value = $this->account()->generateDkimKey();
                    Notification::make()->title(__('admin.mail.dkim_generated'))->body($this->account()->dkim_selector.'._domainkey TXT "'.$value.'"')->persistent()->success()->send();
                }),
        ];
    }

    private function account(): MailAccount
    {
        /** @var MailAccount $account */
        $account = $this->getRecord();

        return $account;
    }
}
