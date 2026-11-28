<?php

namespace App\Filament\Resources\Forms\Tables;

use App\Models\Form;
use App\Support\Forms\FormDefinitions;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class FormsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('name')->label(__('admin.form.name'))->state(fn (Form $record) => $record->label()),
                TextColumn::make('fields_count')->counts('fields')->label(__('admin.form.fields')),
                TextColumn::make('version')->label(__('admin.form.version')),
                TextColumn::make('applies_until_age')->label(__('admin.form.applies_until_age')),
                IconColumn::make('active')->label(__('admin.form.active'))->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('export')->label(__('admin.form.export'))->icon('heroicon-o-arrow-down-tray')->color('gray')
                    ->action(fn (Form $record) => response()->streamDownload(function () use ($record) {
                        echo json_encode(app(FormDefinitions::class)->export($record), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }, 'kasmin-form-'.(Str::slug($record->label()) ?: $record->id).'.json', ['Content-Type' => 'application/json'])),
            ]);
    }
}
