<?php

namespace App\Filament\Resources\OutgoingMails;

use App\Filament\Resources\OutgoingMails\Pages\ListOutgoingMails;
use App\Filament\Resources\OutgoingMails\Schemas\OutgoingMailForm;
use App\Filament\Resources\OutgoingMails\Tables\OutgoingMailsTable;
use App\Filament\Support\AreaAccess;
use App\Filament\Support\InArea;
use App\Models\OutgoingMail;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class OutgoingMailResource extends Resource
{
    use AreaAccess;
    use InArea;

    public static function area(): string
    {
        return 'mailings';
    }

    protected static ?string $model = OutgoingMail::class;

    public static function getModelLabel(): string
    {
        return __('admin.mail.log');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.mail.log_plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    public static function form(Schema $schema): Schema
    {
        return OutgoingMailForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OutgoingMailsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('to_address')->label(__('admin.mail.to')),
            TextEntry::make('status')->label(__('admin.event.status'))->formatStateUsing(fn ($state) => __('admin.mail.statuses.'.$state)),
            TextEntry::make('sent_at')->label(__('admin.mail.sent_at'))->dateTime()->placeholder('–'),
            TextEntry::make('last_error')->label(__('admin.mail.error'))->placeholder('–')->columnSpanFull(),
            TextEntry::make('subject')->label(__('admin.mail.subject'))->columnSpanFull(),
            TextEntry::make('text')->label(__('admin.mail.body'))->columnSpanFull()->extraAttributes(['style' => 'white-space: pre-wrap']),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOutgoingMails::route('/'),
        ];
    }
}
