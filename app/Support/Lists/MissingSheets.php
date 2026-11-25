<?php

namespace App\Support\Lists;

use App\Models\Event;
use App\Models\Household;
use App\Models\MailTemplate;
use App\Models\OutgoingMail;
use App\Models\Participation;
use App\Models\Person;
use App\Support\Audit\Audit;
use App\Support\Automations\Families;
use App\Support\Forms\Forms;
use App\Support\Mail\Composer;
use Illuminate\Support\Collection;

/**
 * Families whose safety sheets are missing or not confirmed, and the reminder to selected ones (SF19a).
 */
class MissingSheets
{
    /** A family is not reminded by hand again within this number of hours. */
    public const PAUSE_HOURS = 12;

    public function __construct(private readonly EventLists $lists, private readonly Forms $forms, private readonly Composer $composer) {}

    /** @return array<string, string> */
    public function labels(Event $event, ?string $role = null): array
    {
        return $this->families($event, $role)->map(fn (array $family) => Families::label($family))->all();
    }

    /**
     * @param  list<string>  $keys
     * @return array{sent: int, skipped: int}
     */
    public function remind(Event $event, array $keys, ?string $role = null): array
    {
        $sent = $skipped = 0;
        $reminded = [];
        $template = MailTemplate::query()->where('key', 'safety_sheet_reminder')->value('id');
        foreach ($this->families($event, $role)->only($keys) as $family) {
            foreach ($family['recipients'] as $recipient) {
                $recent = OutgoingMail::query()->where('person_id', $recipient->id)->where('created_at', '>', now()->subHours(self::PAUSE_HOURS))
                    ->where('mail_template_id', $template)->exists();
                $mail = $recent ? null : $this->composer->queue('safety_sheet_reminder', $recipient, ['event' => $event, 'household' => $family['household'], 'children' => $family['children']]);
                $mail === null ? $skipped++ : $sent++;
            }
            array_push($reminded, ...array_map(fn (Person $p) => $p->id, $family['children']));
        }
        Audit::record('safety_sheet.reminded', $event, null, ['people' => $reminded, 'mails' => $sent]);

        return ['sent' => $sent, 'skipped' => $skipped];
    }

    /** @return Collection<string, array{household: Household|null, children: list<Person>, recipients: list<Person>, participations: list<Participation>}> */
    private function families(Event $event, ?string $role): Collection
    {
        $forms = $this->lists->safetyForms();

        return Families::group($this->lists->participants($event, $role)->filter(function (Participation $p) use ($forms): bool {
            foreach ($forms as $form) {
                if ($p->person !== null && in_array($this->forms->status($form, $p->person), ['missing', 'expired'], true)) {
                    return true;
                }
            }

            return false;
        }));
    }
}
