<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventRole;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Auth\Permissions;
use App\Support\Lists\ListExport;
use App\Support\Reports\Statistics;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Download of the age groups of the statistics page with the chosen fields (AD63). Names and dates of birth leave the
 * system here, so it needs the right to see people, and every export is logged.
 */
class StatisticsExportController extends Controller
{
    public function ageGroups(Request $request, Event $event, string $format): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && Permissions::allows($user, 'events') && Permissions::allows($user, 'people'), 403);
        $data = $request->validate([
            'groups' => ['sometimes', 'integer', 'between:2,8'],
            'role' => ['sometimes', 'nullable', Rule::in(EventRole::KEYS)],
            'fields' => ['sometimes', 'array'], 'fields.*' => [Rule::in(Statistics::AGE_GROUP_FIELDS)],
        ]);
        $groups = (int) ($data['groups'] ?? 3);
        $list = app(Statistics::class)->ageGroupList($event, $groups, filled($data['role'] ?? null) ? [(string) $data['role']] : [], array_values((array) ($data['fields'] ?? [])));
        Audit::record('statistics.age_groups_exported', $event, null, ['format' => $format, 'groups' => $groups, 'fields' => $list->headers, 'rows' => count($list->rows)]);

        return response(app(ListExport::class)->render($list, $format, $event->title), 200, [
            'Content-Type' => ListExport::TYPES[$format],
            'Content-Disposition' => ($format === 'pdf' ? 'inline' : 'attachment').'; filename="'.ListExport::fileName(str($event->title.'-'.$list->title)->ascii()->toString(), $format).'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
