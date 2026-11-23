<?php

namespace App\Filament\Resources\SenderProfiles\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class SenderProfileForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label(__('admin.mail.name'))->required(),
            Select::make('mail_account_id')->label(__('admin.mail.account'))->relationship('account', 'name')->required(),
            TextInput::make('from_name')->label(__('admin.mail.from_name'))->required(),
            TextInput::make('from_address')->label(__('admin.mail.from_address'))->email()->required(),
            TextInput::make('reply_to')->label(__('admin.mail.reply_to'))->email(),
        ]);
    }
}
