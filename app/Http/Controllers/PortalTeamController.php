<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Auth\Permissions;
use App\Support\Lists\EventLists;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Lists of the own events for team members without access to the admin interface (RO01): never health data, and only
 * events they take part in as team member or were assigned to.
 */
class PortalTeamController extends Controller
{
    public const LISTS = ['participants', 'contacts', 'bus', 'birthdays'];

    public function show(Request $request, string $event, string $list = 'participants'): View
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(in_array($list, self::LISTS, true), 404);
        $model = Event::query()->whereIn('id', self::events($user))->where('public_id', $event)->firstOrFail();
        Audit::record('list.viewed', $model, null, ['list' => $list, 'portal' => true]);

        return view('portal.team', ['event' => $model, 'list' => $list, 'table' => app(EventLists::class)->build($model, $list)]);
    }

    /** @return list<int> */
    public static function events(User $user): array
    {
        return Permissions::ownEvents($user, 'events', 'view', admin: false);
    }
}
