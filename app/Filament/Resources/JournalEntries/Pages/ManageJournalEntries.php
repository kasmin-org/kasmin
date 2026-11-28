<?php

namespace App\Filament\Resources\JournalEntries\Pages;

use App\Filament\Resources\CostCenters\CostCenterResource;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Support\Accounting\Journal;
use Filament\Actions\Action;
use Filament\Resources\Pages\ManageRecords;

class ManageJournalEntries extends ManageRecords
{
    protected static string $resource = JournalEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('book')->label(__('accounting.book'))->icon('heroicon-o-plus')->visible(fn () => JournalEntryResource::canCreate())
                ->modalWidth('6xl')->schema(JournalEntryResource::entrySchema())
                ->action(function (array $data) {
                    $lines = array_map(fn (array $l) => ['account' => (int) $l['account'], 'debit' => (int) ($l['debit'] ?? 0), 'credit' => (int) ($l['credit'] ?? 0),
                        'cost_center' => $l['cost_center'] ?? null, 'fund' => $l['fund'] ?? null, 'text' => $l['text'] ?? null], array_values($data['lines'] ?? []));
                    CostCenterResource::attempt(fn () => app(Journal::class)->post(['date' => $data['date'], 'text' => $data['text'], 'receipt' => $data['receipt'] ?? null, 'receipt_path' => $data['receipt_path'] ?? null], $lines));
                }),
        ];
    }
}
