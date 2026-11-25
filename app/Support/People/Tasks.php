<?php

namespace App\Support\People;

use App\Models\Form;
use App\Models\Household;
use App\Models\Invoice;
use App\Models\Participation;
use App\Models\Person;
use App\Support\Forms\Forms;
use App\Support\Forms\RegistrationAnswers;
use Illuminate\Support\Collection;

/**
 * Open tasks on the start page of the portal (GP13), most urgent first.
 */
class Tasks
{
    public function __construct(private readonly Forms $forms, private readonly RegistrationAnswers $answers) {}

    /**
     * @param  Collection<int, Household>  $households
     * @param  Collection<int, Invoice>  $invoices
     * @return list<array{text: string, url: string, urgent: bool}>
     */
    public function for(Person $person, Collection $households, Collection $invoices): array
    {
        $tasks = [];
        $forms = Form::query()->where('kind', 'person')->where('active', true)->where('family_emergency_contacts', true)->with('fields')->get();
        foreach ([$person, ...$person->wards()->get()->all()] as $member) {
            $active = $member->participations()->with('event')->get()->filter(fn (Participation $p) => $p->visibleInPortal($person) && $p->event !== null && $p->event->ends_at->isFuture());
            foreach ($active->where('status', 'offered') as $p) {
                $tasks[] = ['text' => (string) __('portal.task_offer', ['name' => $member->first_name, 'event' => $p->event?->title, 'date' => $p->offer_expires_at?->isoFormat('LL') ?? '–']), 'url' => route('portal.child', $member->public_id), 'urgent' => true];
            }
            $taking = $active->whereIn('status', ['registered', 'confirmed', 'requested']);
            foreach ($taking as $p) {
                if ($p->event === null) {
                    continue;
                }
                $form = $this->answers->form($p->event, $p->role_key);
                if ($form !== null && $this->answers->editable($p->event) && array_diff($form->fields->where('required', true)->pluck('key')->all(), array_keys($this->answers->values($p, $form))) !== []) {
                    $tasks[] = ['text' => (string) __('portal.task_answers', ['name' => $member->first_name, 'event' => $p->event->title]), 'url' => route('portal.child.answers', [$member->public_id, $p->id]), 'urgent' => false];
                }
            }
            if (! $member->is($person) && $taking->isNotEmpty()) {
                foreach ($forms as $form) {
                    if (in_array($this->forms->status($form, $member), ['missing', 'expired'], true)) {
                        $tasks[] = ['text' => (string) __('portal.task_form', ['name' => $member->first_name, 'form' => $form->label()]), 'url' => route('portal.form.edit', [$member->public_id, $form->key]), 'urgent' => true];
                    }
                }
            }
        }
        foreach ($invoices->whereIn('status', ['issued', 'partially_paid'])->where('type', 'invoice') as $invoice) {
            $tasks[] = ['text' => (string) __('portal.task_invoice', ['number' => $invoice->number, 'amount' => Invoice::money($invoice->openCents(), $invoice->currency), 'date' => $invoice->due_on?->isoFormat('LL') ?? '–']), 'url' => route('portal').'#invoices', 'urgent' => $invoice->isOverdue()];
        }
        foreach ($households as $household) {
            if ($household->members->contains(fn (Person $m) => $m->is($person) && $m->getRelationValue('pivot')?->getAttribute('role') === 'guardian') && (blank($household->getAttribute('street')) || blank($household->getAttribute('city')))) {
                $tasks[] = ['text' => (string) __('portal.task_address'), 'url' => route('portal.profile'), 'urgent' => false];
            }
        }
        usort($tasks, fn (array $a, array $b) => (int) $b['urgent'] <=> (int) $a['urgent']);

        return $tasks;
    }
}
