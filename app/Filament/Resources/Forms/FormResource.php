<?php

namespace App\Filament\Resources\Forms;

use App\Filament\Resources\Forms\Pages\CreateForm;
use App\Filament\Resources\Forms\Pages\EditForm;
use App\Filament\Resources\Forms\Pages\ListForms;
use App\Filament\Resources\Forms\Schemas\FormForm;
use App\Filament\Resources\Forms\Tables\FormsTable;
use App\Filament\Support\AreaAccess;
use App\Filament\Support\InArea;
use App\Models\Form;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class FormResource extends Resource
{
    use AreaAccess;
    use InArea;

    public static function area(): string
    {
        return 'forms';
    }

    protected static ?string $model = Form::class;

    public static function getModelLabel(): string
    {
        return __('admin.form.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.form.plural');
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    public static function form(Schema $schema): Schema
    {
        return FormForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FormsTable::configure($table);
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
            'index' => ListForms::route('/'),
            'create' => CreateForm::route('/create'),
            'edit' => EditForm::route('/{record}/edit'),
        ];
    }
}
