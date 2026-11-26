<?php

namespace App\Filament\Resources\MailAccounts\Pages;

use App\Filament\Resources\MailAccounts\MailAccountResource;
use App\Models\MailAccount;
use Filament\Resources\Pages\CreateRecord;

class CreateMailAccount extends CreateRecord
{
    use SavesSender;

    protected static string $resource = MailAccountResource::class;

    public function mount(): void
    {
        $account = MailAccount::main();
        if ($account !== null) { // there is one account: change it instead of adding a second
            $this->redirect(MailAccountResource::getUrl('edit', ['record' => $account]));

            return;
        }
        parent::mount();
        $this->form->fill($this->withSender([]));
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->takeSender($data);
    }

    protected function afterCreate(): void
    {
        /** @var MailAccount $account */
        $account = $this->getRecord();
        $this->storeSender($account);
    }

    protected function getRedirectUrl(): string
    {
        return MailAccountResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
