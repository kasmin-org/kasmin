<?php

namespace App\Filament\Resources\Periods\Pages;

use App\Filament\Resources\Periods\PeriodResource;
use App\Models\Period;
use App\Support\Accounting\DocumentBookings;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManagePeriods extends ManageRecords
{
    protected static string $resource = PeriodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('book_documents')->label(__('accounting.periods.book_documents'))->icon('heroicon-o-document-check')->color('gray')
                ->visible(fn () => PeriodResource::canCreate() && Period::query()->exists())
                ->requiresConfirmation()->modalDescription(__('accounting.periods.book_documents_hint'))
                ->action(function () {
                    $bookings = app(DocumentBookings::class);
                    $made = $bookings->backfill();
                    $note = Notification::make()->title(trans_choice('accounting.periods.booked', $made, ['count' => $made]));
                    ($bookings->failures === [] ? $note->success() : $note->warning()->persistent()->body(__('accounting.periods.booking_failures', ['list' => implode(' ', $bookings->failures)])))->send();
                }),
            PeriodResource::createAction(),
        ];
    }
}
