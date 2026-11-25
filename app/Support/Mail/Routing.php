<?php

namespace App\Support\Mail;

use App\Models\MailTemplate;
use App\Models\Participation;
use App\Models\Person;

/**
 * Who gets a mail about a person (PE06): as set in the template. Minors' mails go to the legal guardians by default; what
 * somebody registered without them goes only to that person (PE05a).
 */
class Routing
{
    /** @return list<Person> */
    public static function recipients(string $templateKey, Person $person, ?Participation $participation = null): array
    {
        if ($participation?->isOwn() === true) {
            return [$person];
        }
        $guardians = $person->guardians()->get()->all();
        $audience = MailTemplate::query()->where('key', $templateKey)->value('audience') ?? 'guardians';

        return array_values(match ($audience) {
            'person' => $person->primaryEmail() !== null ? [$person] : $guardians,
            'both' => $guardians === [] ? [$person] : ($person->primaryEmail() !== null ? [...$guardians, $person] : $guardians),
            default => $guardians === [] ? [$person] : $guardians,
        });
    }
}
