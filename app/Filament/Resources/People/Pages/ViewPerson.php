<?php

namespace App\Filament\Resources\People\Pages;

use App\Filament\Resources\People\PersonResource;
use App\Mail\LoginLinkMail;
use App\Models\Person;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Auth\LoginLinks;
use App\Support\Auth\Permissions;
use App\Support\Auth\PortalPerson;
use App\Support\Privacy\DataExport;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Mail;

class ViewPerson extends ViewRecord
{
    protected static string $resource = PersonResource::class;

    private function person(): Person
    {
        /** @var Person $person */
        $person = $this->getRecord();

        return $person;
    }

    protected function getHeaderActions(): array
    {
        return [
            // Read-only preview of the portal as this person sees it (AD25), logged.
            Action::make('view_as')->label(__('portal.view_as'))->icon('heroicon-o-eye')->color('gray')
                ->visible(fn () => auth()->user() instanceof User && PortalPerson::mayPreview(auth()->user()) && $this->getRecord() instanceof Person && $this->getRecord()->getAttribute('anonymised_at') === null)
                ->requiresConfirmation()->modalDescription(__('portal.view_as_hint'))
                ->action(function () {
                    /** @var Person $person */
                    $person = $this->getRecord();
                    session()->put(PortalPerson::SESSION_KEY, $person->id);
                    Audit::record('portal.view_as_started', $person);
                    $this->redirect(route('portal'));
                }),
            Action::make('timeline')->label(__('people.timeline'))->icon('heroicon-o-clock')->color('gray')->url(fn () => PersonResource::getUrl('timeline', ['record' => $this->getRecord()])),
            // The link only goes to the address the person already uses for the login (AD24).
            Action::make('login_link')->label(__('people.login_link'))->icon('heroicon-o-key')->color('gray')->requiresConfirmation()
                ->visible(fn () => Permissions::current('people', 'edit') && $this->person()->user !== null && $this->person()->getAttribute('anonymised_at') === null)
                ->action(function () {
                    $person = $this->person();
                    $user = $person->user;
                    if ($user !== null) {
                        ['token' => $token, 'code' => $code] = app(LoginLinks::class)->issue($user);
                        Mail::to($user->email)->locale($person->locale)->send(new LoginLinkMail($person->first_name, route('login.link', $token), $code, LoginLinks::LINK_MINUTES));
                        Audit::record('login.link_sent_by_admin', $person);
                        Notification::make()->title(__('people.login_link_sent'))->success()->send();
                    }
                }),
            Action::make('revoke_links')->label(__('people.revoke_links'))->icon('heroicon-o-link-slash')->color('gray')->requiresConfirmation()
                ->modalDescription(__('people.revoke_links_hint'))
                ->visible(fn () => Permissions::current('people', 'edit') && $this->person()->user !== null)
                ->action(function () {
                    $user = $this->person()->user;
                    if ($user !== null) {
                        $count = app(LoginLinks::class)->revokeMailLinks($user);
                        Notification::make()->title(trans_choice('portal.mail_links_ended', $count, ['count' => $count]))->success()->send();
                    }
                }),
            Action::make('export')->label(__('portal.export'))->icon('heroicon-o-arrow-down-tray')->color('gray')->requiresConfirmation()
                ->visible(fn () => Permissions::administrator() && $this->person()->getAttribute('anonymised_at') === null)
                ->action(function () {
                    $person = $this->person();
                    Audit::record('privacy.data_exported', $person, null, ['source' => 'admin']);
                    $json = (string) json_encode(app(DataExport::class)->for($person), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                    return response()->streamDownload(function () use ($json) {
                        echo $json;
                    }, 'kasmin-data-'.$person->id.'.json', ['Content-Type' => 'application/json', 'Cache-Control' => 'private, no-store']);
                }),
            EditAction::make(),
        ];
    }
}
