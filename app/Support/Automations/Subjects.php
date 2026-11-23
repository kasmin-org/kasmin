<?php

namespace App\Support\Automations;

use App\Models\Automation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Per-subject counters of an automation (how often and when a reminder went to a family, whether a notice was
 * already given), so repeated runs respect intervals and maximums.
 */
class Subjects
{
    /** @return array{count: int, last_at: Carbon|null} */
    public function get(Automation $automation, string $subject): array
    {
        $row = DB::table('automation_subjects')->where('automation_id', $automation->id)->where('subject', $subject)->first();

        return ['count' => (int) ($row->count ?? 0), 'last_at' => isset($row->last_at) ? Carbon::parse($row->last_at) : null];
    }

    public function touch(Automation $automation, string $subject): void
    {
        $updated = DB::table('automation_subjects')->where('automation_id', $automation->id)->where('subject', $subject)
            ->update(['count' => DB::raw('count + 1'), 'last_at' => now(), 'updated_at' => now()]);
        if ($updated === 0) {
            DB::table('automation_subjects')->insert(['automation_id' => $automation->id, 'subject' => $subject, 'count' => 1, 'last_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
