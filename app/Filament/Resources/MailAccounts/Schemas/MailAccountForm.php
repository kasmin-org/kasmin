<?php

namespace App\Filament\Resources\MailAccounts\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MailAccountForm
{
    public static function configure(Schema $schema): Schema
    {
        $hint = fn (string $k) => ['heroicon-m-question-mark-circle', __('admin.mail.hints.'.$k)];

        return $schema->components([
            // The sender is stored next to the account (SenderProfile); the pages read and write it.
            Section::make(__('admin.mail.sender'))->description(__('admin.mail.sender_hint'))->columns(2)->schema([
                TextInput::make('from_name')->label(__('admin.mail.from_name'))->required()->maxLength(120),
                TextInput::make('from_address')->label(__('admin.mail.from_address'))->email()->required()->maxLength(254),
                TextInput::make('reply_to')->label(__('admin.mail.reply_to'))->email()->maxLength(254)->hintIcon(...$hint('reply_to')),
            ]),
            Section::make(__('admin.mail.account'))->columns(2)->schema([
                Select::make('delivery')->label(__('admin.mail.delivery'))->options(['smtp' => __('admin.mail.delivery_smtp'), 'direct' => __('admin.mail.delivery_direct')])
                    ->default('smtp')->required()->live()->hintIcon(...$hint('delivery')),
            ]),
            Section::make('DKIM')->description(__('admin.mail.dkim_hint'))->columns(2)->schema([
                TextInput::make('dkim_domain')->label(__('admin.mail.dkim_domain')),
                TextInput::make('dkim_selector')->label(__('admin.mail.dkim_selector'))->alphaDash(),
            ]),
            Section::make('SMTP')->columns(2)->visible(fn ($get) => $get('delivery') !== 'direct')->schema([
                TextInput::make('smtp_host')->label(__('admin.mail.host'))->required(),
                TextInput::make('smtp_port')->label(__('admin.mail.port'))->numeric()->default(465)->required(),
                Select::make('smtp_encryption')->label(__('admin.mail.encryption'))->options(['ssl' => 'SSL/TLS (465)', 'tls' => 'STARTTLS (587)'])->default('ssl')->required(),
                TextInput::make('smtp_username')->label(__('admin.mail.username')),
                TextInput::make('smtp_password')->label(__('admin.mail.password'))->password()->revealable()->dehydrated(fn ($state) => filled($state)),
            ]),
            Section::make('IMAP')->description(__('admin.mail.imap_hint'))->columns(2)->schema([
                TextInput::make('imap_host')->label(__('admin.mail.host')),
                TextInput::make('imap_port')->label(__('admin.mail.port'))->numeric()->default(993),
                TextInput::make('imap_username')->label(__('admin.mail.username')),
                TextInput::make('imap_password')->label(__('admin.mail.password'))->password()->revealable()->dehydrated(fn ($state) => filled($state)),
                TextInput::make('imap_sent_folder')->label(__('admin.mail.sent_folder'))->hintIcon(...$hint('sent_folder')),
            ]),
            Section::make(__('admin.mail.rate_limits'))->description(__('admin.mail.hints.rate_limits'))->columns(3)->schema([
                TextInput::make('rate_per_minute')->label(__('admin.mail.per_minute'))->numeric()->minValue(1),
                TextInput::make('rate_per_hour')->label(__('admin.mail.per_hour'))->numeric()->minValue(1),
                TextInput::make('rate_per_day')->label(__('admin.mail.per_day'))->numeric()->minValue(1),
            ]),
        ]);
    }
}
