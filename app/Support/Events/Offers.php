<?php

namespace App\Support\Events;

use App\Models\Event;
use App\Models\Participation;
use App\Models\Person;
use App\Support\Mail\Composer;
use App\Support\Mail\Routing;
use Illuminate\Support\Carbon;

/**
 * Offers a place by hand and tells the family (RR03, WL02): out of turn with a reason, or to somebody on the
 * waitlist. Used by the admin interface and the REST API, so both send the same mail.
 */
class Offers
{
    public function __construct(private readonly Rounds $rounds, private readonly Composer $composer) {}

    /**
     * @param  string  $template  round_offer (out of turn) or waitlist_offer (from the waitlist)
     * @return Participation|null null if there is no place, or the person already has one
     */
    public function offer(Event $event, Person $person, string $roleKey, Carbon $until, ?string $reason, string $template = 'round_offer'): ?Participation
    {
        $offered = $this->rounds->offer($event, $person, $roleKey, $until, null, $reason);
        if ($offered === null) {
            return null;
        }
        foreach (Routing::recipients($template, $person, $offered) as $recipient) {
            $this->composer->queue($template, $recipient, ['event' => $event, 'participation' => $offered->load('event', 'person'), 'household' => $person->households()->first(), 'children' => [$person]]);
        }

        return $offered;
    }
}
