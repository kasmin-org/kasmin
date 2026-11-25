<?php

namespace App\Support\People;

use App\Models\AuditEvent;
use App\Models\FormField;
use App\Models\FormResponse;
use App\Models\Invoice;
use App\Models\OutgoingMail;
use App\Models\Participation;
use App\Models\Payment;
use App\Models\Person;
use App\Models\User;
use App\Support\Auth\Permissions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Everything that happened with a person (AD21), limited to what the viewer may see. No values of confidential fields.
 */
class Timeline
{
    public const TYPES = ['registration', 'group', 'mail', 'finance', 'form', 'login', 'data'];

    /** @return list<array{at: Carbon, type: string, text: string, url: string|null}> newest first */
    public function for(Person $person, User $viewer, ?string $type = null, int $limit = 200): array
    {
        $items = [];
        $add = function (?Carbon $at, string $kind, string $text, ?string $url = null) use (&$items, $type): void {
            if ($at !== null && ($type === null || $type === $kind)) {
                $items[] = ['at' => $at, 'type' => $kind, 'text' => $text, 'url' => $url];
            }
        };
        $events = Permissions::allows($viewer, 'events') ? null : Permissions::ownEvents($viewer);
        if ($events !== []) {
            $participations = Participation::query()->where('person_id', $person->id)->when($events !== null, fn ($q) => $q->whereIn('event_id', $events ?? []))->with('event')->get()->keyBy('id');
            foreach (AuditEvent::query()->where('subject_type', (new Participation)->getMorphClass())->whereIn('subject_id', $participations->keys())->latest('id')->limit($limit)->get() as $audit) {
                $p = $participations->get($audit->getAttribute('subject_id'));
                $status = data_get($audit->changes, 'status');
                $new = is_array($status) ? ($status['new'] ?? $status[1] ?? null) : $status;
                $add($this->date($audit->getAttribute('occurred_at')), 'registration', trim($p?->event?->title.': '.(is_string($new) ? __('events.status.'.$new) : $audit->getAttribute('action'))));
            }
        }
        foreach (! Permissions::allows($viewer, 'people') ? [] : DB::table('group_memberships')->join('groups', 'groups.id', '=', 'group_memberships.group_id')->where('person_id', $person->id)->get(['groups.name', 'starts_on', 'ends_on', 'source']) as $row) {
            $add($this->date($row->starts_on), 'group', (string) __('people.timeline_joined', ['group' => $row->name, 'source' => $row->source ?? '–']));
            $add($this->date($row->ends_on), 'group', (string) __('people.timeline_left', ['group' => $row->name]));
        }
        if (Permissions::allows($viewer, 'mailings')) {
            foreach (OutgoingMail::query()->where('person_id', $person->id)->latest('id')->limit($limit)->get() as $mail) {
                $add($this->date($mail->getAttribute('sent_at') ?? $mail->created_at), 'mail', $mail->subject.' ('.__('admin.mail.statuses.'.$mail->status).')', url('/admin/outgoing-mails?tableAction=view&tableActionRecord='.$mail->id));
            }
        }
        if (Permissions::allows($viewer, 'finance')) {
            $invoices = Invoice::query()->where('status', '!=', 'draft')->where(fn ($q) => $q->where('payer_id', $person->id)->orWhereHas('lines', fn ($l) => $l->where('person_id', $person->id)))->get();
            foreach ($invoices as $invoice) {
                $add($invoice->issued_on, 'finance', __('finance.type.'.$invoice->type).' '.$invoice->number.': '.Invoice::money($invoice->total_cents, $invoice->currency), url('/admin/invoices/'.$invoice->id));
            }
            foreach (Payment::query()->whereIn('invoice_id', $invoices->pluck('id'))->with('invoice')->get() as $payment) {
                $add($this->date($payment->paid_on), 'finance', (string) __('people.timeline_paid', ['amount' => Invoice::money($payment->amount_cents), 'number' => $payment->invoice?->number]), url('/admin/invoices/'.$payment->invoice_id));
            }
        }
        $safety = Permissions::allows($viewer, 'safety');
        foreach (FormResponse::query()->where('person_id', $person->id)->with('form.fields')->latest('id')->limit($limit)->get() as $response) {
            $changed = $response->form?->fields->whereIn('key', $response->changed_keys ?? [])->filter(fn (FormField $f) => $safety || ! $f->isConfidential())->map(fn (FormField $f) => $f->labelText())->implode(', ');
            $add($response->created_at, 'form', $response->form?->label().($response->confirmed_at !== null ? ' – '.__('forms.status.confirmed') : '').($changed !== null && $changed !== '' ? ': '.$changed : ''));
        }
        $user = $person->user;
        if ($user !== null) {
            foreach (AuditEvent::query()->where('action', 'like', 'login.%')->where(fn ($q) => $q->where(fn ($q) => $q->where('actor_type', 'user')->where('actor_id', $user->id))->orWhere(fn ($q) => $q->where('subject_type', $user->getMorphClass())->where('subject_id', $user->id)))->latest('id')->limit($limit)->get() as $audit) {
                $add($this->date($audit->getAttribute('occurred_at')), 'login', (string) $audit->getAttribute('action'));
            }
        }
        foreach (AuditEvent::query()->where('subject_type', $person->getMorphClass())->where('subject_id', $person->id)->where('action', 'not like', 'form.%')->latest('id')->limit($limit)->get() as $audit) {
            $add($this->date($audit->getAttribute('occurred_at')), 'data', (string) $audit->getAttribute('action'));
        }
        usort($items, fn (array $a, array $b) => $b['at']->timestamp <=> $a['at']->timestamp);

        return array_slice($items, 0, $limit);
    }

    private function date(mixed $value): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value);
    }
}
