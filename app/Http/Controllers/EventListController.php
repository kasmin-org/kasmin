<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Person;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Lists\CampFolder;
use App\Support\Lists\EventLists;
use App\Support\Lists\ListExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Downloads of event lists and the camp folder; every export is logged (AD13, SF26).
 */
class EventListController extends Controller
{
    public function list(Request $request, Event $event, string $key, string $format): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && EventLists::mayView($user, $key, $event->id), 403);
        abort_unless(in_array($format, ListExport::FORMATS, true), 404);
        $role = $request->string('role')->toString() ?: null;
        $list = app(EventLists::class)->build($event, $key, $role);
        Audit::record('list.exported', $event, null, ['list' => $key, 'format' => $format, 'role' => $role, 'confidential' => $list->confidential]);

        return $this->file(app(ListExport::class)->render($list, $format, $event->title), $event->title.'-'.$list->title, $format);
    }

    public function folder(Request $request, Event $event, ?Person $person = null): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && EventLists::mayViewSafety($user, $event->id), 403);
        if ($person !== null) {
            abort_unless($event->participations()->where('person_id', $person->id)->whereIn('status', EventLists::STATUSES)->exists(), 404);
        }
        Audit::record('camp_folder.exported', $person ?? $event, null, ['event_id' => $event->id]);

        return $this->file(app(CampFolder::class)->render($event, $person), $event->title.'-'.__('lists.folder').($person !== null ? '-'.$person->fullName() : ''), 'pdf');
    }

    private function file(string $content, string $name, string $format): Response
    {
        return response($content, 200, [
            'Content-Type' => ListExport::TYPES[$format],
            'Content-Disposition' => ($format === 'pdf' ? 'inline' : 'attachment').'; filename="'.ListExport::fileName(str($name)->ascii()->toString(), $format).'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
