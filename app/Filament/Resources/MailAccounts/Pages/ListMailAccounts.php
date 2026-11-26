<?php

namespace App\Filament\Resources\MailAccounts\Pages;

use App\Filament\Resources\MailAccounts\MailAccountResource;
use App\Models\MailAccount;
use Filament\Resources\Pages\Page;

/**
 * There is one mail account with one sender, so there is no list: the menu entry leads straight to its form – to the
 * empty one as long as nothing is set up.
 */
class ListMailAccounts extends Page
{
    protected static string $resource = MailAccountResource::class;

    public function mount(): void
    {
        $account = MailAccount::main();
        $this->redirect($account === null ? MailAccountResource::getUrl('create') : MailAccountResource::getUrl('edit', ['record' => $account]));
    }
}
