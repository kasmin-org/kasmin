<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventDocument;
use App\Models\Participation;
use App\Models\PublicForm;
use App\Models\User;
use App\Support\Lists\EventLists;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public page of an event, its image and its documents (EE01, EE07).
 */
class PublicEventController extends Controller
{
    public function show(string $publicId): View
    {
        $event = $this->event($publicId);

        return view('public.event', [
            'event' => $event, 'documents' => $event->documents()->where('visibility', 'public')->get(),
            'forms' => PublicForm::query()->where('event_id', $event->id)->where('active', true)->get()->filter(fn (PublicForm $f) => $f->state() === 'open'),
        ]);
    }

    public function image(string $publicId): Response
    {
        $event = $this->event($publicId);
        abort_if($event->image_path === null || ! Storage::disk('local')->exists($event->image_path), 404);

        return Storage::disk('local')->response($event->image_path, null, ['Cache-Control' => 'public, max-age=3600']);
    }

    public function document(Request $request, EventDocument $document): Response
    {
        $event = $document->event;
        abort_if($event === null, 404);
        $public = $document->visibility === 'public' && $event->public_page && ! in_array($event->status, ['draft', 'archived'], true);
        $user = $request->user();
        abort_unless($public || ($user instanceof User && self::mayRead($user, $event)), 404);
        abort_unless(Storage::disk('local')->exists($document->path), 404);
        $name = str($document->title)->ascii()->replaceMatches('/[^A-Za-z0-9_.-]+/', '-')->trim('-').'.'.pathinfo($document->path, PATHINFO_EXTENSION);

        return Storage::disk('local')->download($document->path, $name, ['Cache-Control' => 'private, no-store']);
    }

    /** Participants, their legal guardians, and the team. */
    private static function mayRead(User $user, Event $event): bool
    {
        if ($user->person === null) {
            return false;
        }
        if ($user->hasAnyRole(['administrator', 'board', 'camp_leader'])) {
            return true;
        }
        $active = Participation::query()->where('event_id', $event->id)->whereIn('status', [...EventLists::STATUSES, 'offered']);

        return (clone $active)->where('person_id', $user->person->id)->exists()
            || (clone $active)->whereIn('person_id', $user->person->wards()->pluck('people.id'))->get()->contains(fn (Participation $p) => $p->visibleInPortal($user->person));
    }

    private function event(string $publicId): Event
    {
        return Event::query()->where('public_id', $publicId)->where('public_page', true)->whereNotIn('status', ['draft', 'archived'])->with('roles', 'prices')->firstOrFail();
    }
}
