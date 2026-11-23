<?php

namespace App\Filament\Resources\MailAccounts\Tables;

use App\Models\MailAccount;
use App\Support\Mail\Sender;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Throwable;

class MailAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label(__('admin.mail.name')),
            TextColumn::make('smtp_host')->label('SMTP'),
            TextColumn::make('mode')->label(__('admin.mail.mode'))->state(fn (MailAccount $r) => $r->canReceive() ? __('admin.mail.send_receive') : __('admin.mail.send_only'))->badge(),
            IconColumn::make('is_default')->label(__('admin.mail.is_default'))->boolean(),
        ])->recordActions([
            Action::make('test')->label(__('admin.mail.test'))->icon('heroicon-o-paper-airplane')
                ->schema([TextInput::make('to')->label(__('admin.mail.test_to'))->email()->required()->default(fn () => auth()->user()?->getAttribute('email'))])
                ->action(function (MailAccount $record, array $data) {
                    try {
                        app(Sender::class)->test($record, (string) $data['to']);
                        Notification::make()->title(__('admin.mail.test_ok'))->success()->send();
                    } catch (Throwable $e) {
                        Notification::make()->title(__('admin.mail.test_failed'))->body($e->getMessage())->danger()->send();
                    }
                }),
            EditAction::make(),
        ]);
    }
}
