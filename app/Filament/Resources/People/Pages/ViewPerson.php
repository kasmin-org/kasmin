<?php

namespace App\Filament\Resources\People\Pages;

use App\Filament\Resources\People\PersonResource;
use App\Models\Person;
use App\Support\Audit\Audit;
use App\Support\Auth\PortalPerson;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewPerson extends ViewRecord
{
    protected static string $resource = PersonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Read-only preview of the portal as this person sees it (AD25), logged.
            Action::make('view_as')->label(__('portal.view_as'))->icon('heroicon-o-eye')->color('gray')
                ->visible(fn () => $this->getRecord() instanceof Person && $this->getRecord()->getAttribute('anonymised_at') === null)
                ->requiresConfirmation()->modalDescription(__('portal.view_as_hint'))
                ->action(function () {
                    /** @var Person $person */
                    $person = $this->getRecord();
                    session()->put(PortalPerson::SESSION_KEY, $person->id);
                    Audit::record('portal.view_as_started', $person);
                    $this->redirect(route('portal'));
                }),
            EditAction::make(),
        ];
    }
}
