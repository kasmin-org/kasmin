<?php

namespace App\Filament\Resources\MailTemplates;

use App\Filament\Resources\MailTemplates\Pages\CreateMailTemplate;
use App\Filament\Resources\MailTemplates\Pages\EditMailTemplate;
use App\Filament\Resources\MailTemplates\Pages\ListMailTemplates;
use App\Filament\Resources\MailTemplates\Pages\PreviewMailTemplate;
use App\Filament\Resources\MailTemplates\Schemas\MailTemplateForm;
use App\Filament\Resources\MailTemplates\Tables\MailTemplatesTable;
use App\Filament\Support\AreaAccess;
use App\Filament\Support\InArea;
use App\Models\MailTemplate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class MailTemplateResource extends Resource
{
    use AreaAccess;
    use InArea;

    public static function area(): string
    {
        return 'mailings';
    }

    protected static ?string $model = MailTemplate::class;

    public static function getModelLabel(): string
    {
        return __('admin.mail.template');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.mail.template_plural');
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    public static function form(Schema $schema): Schema
    {
        return MailTemplateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MailTemplatesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMailTemplates::route('/'),
            'create' => CreateMailTemplate::route('/create'),
            'edit' => EditMailTemplate::route('/{record}/edit'),
            'preview' => PreviewMailTemplate::route('/{record}/preview'),
        ];
    }
}
