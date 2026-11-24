<?php

namespace App\Filament\Resources\ImportBatches;

use App\Filament\Resources\ImportBatches\Pages\ListImportBatches;
use App\Models\ImportBatch;
use App\Models\User;
use App\Support\Import\PeopleImporter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Import batches with statistics and rollback (MI06).
 */
class ImportBatchResource extends Resource
{
    protected static ?string $model = ImportBatch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    public static function getModelLabel(): string
    {
        return __('import.batch');
    }

    public static function getPluralModelLabel(): string
    {
        return __('import.batches');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.people');
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasAnyRole(['administrator', 'board']);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('id')->label('#'),
            TextColumn::make('created_at')->label(__('import.time'))->dateTime(),
            TextColumn::make('source')->label(__('import.source'))->badge(),
            TextColumn::make('stats')->label(__('import.result'))->state(fn (ImportBatch $r) => __('import.stats', [
                'created' => $r->stats['created'] ?? 0, 'updated' => $r->stats['updated'] ?? 0, 'skipped' => $r->stats['skipped'] ?? 0,
            ])),
            TextColumn::make('createdBy.name')->label(__('import.by'))->placeholder(__('import.by_api')),
            TextColumn::make('status')->label(__('import.status'))->badge()->formatStateUsing(fn (string $state) => __('import.statuses.'.$state))
                ->color(fn (string $state) => $state === 'imported' ? 'success' : 'gray'),
        ])->recordActions([
            Action::make('rollback')->label(__('import.rollback'))->icon('heroicon-o-arrow-uturn-left')->color('danger')->requiresConfirmation()
                ->modalDescription(__('import.rollback_hint'))
                ->visible(fn (ImportBatch $r) => $r->status === 'imported')
                ->action(function (ImportBatch $r) {
                    try {
                        app(PeopleImporter::class)->rollback($r);
                        Notification::make()->title(__('import.rolled_back'))->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title($e->getMessage())->danger()->persistent()->send();
                    }
                }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListImportBatches::route('/')];
    }
}
