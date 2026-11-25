<?php

namespace App\Http\Controllers;

use App\Models\Participation;
use App\Models\Person;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Auth\Permissions;
use App\Support\Auth\PortalPerson;
use App\Support\Events\ParticipationConfirmation;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Confirmation of participation (PA07): for the team in the admin interface, and for the person or the legal
 * guardians in the portal.
 */
class ParticipationPdfController extends Controller
{
    public function admin(Request $request, Participation $participation): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && Permissions::allowsEvent($user, $participation->event_id), 403);
        abort_unless(ParticipationConfirmation::available($participation), 404);

        return $this->pdf($participation, $participation->person?->locale);
    }

    public function portal(Request $request, string $child, Participation $participation): Response
    {
        $viewer = PortalPerson::for($request);
        $person = Person::query()->where('public_id', $child)->first();
        abort_unless($viewer !== null && $person !== null && $participation->person_id === $person->id
            && ($person->is($viewer) || $person->guardians()->whereKey($viewer->id)->exists())
            && $participation->visibleInPortal($viewer) && ParticipationConfirmation::available($participation), 404);
        Audit::record('participation.confirmation_downloaded', $participation);

        return $this->pdf($participation, $viewer->locale);
    }

    private function pdf(Participation $participation, ?string $locale): Response
    {
        $service = app(ParticipationConfirmation::class);

        return response($service->render($participation, $locale), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$service->fileName($participation).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
