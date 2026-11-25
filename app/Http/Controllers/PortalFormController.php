<?php

namespace App\Http\Controllers;

use App\Models\Form;
use App\Models\FormField;
use App\Models\FormResponse;
use App\Models\Household;
use App\Models\Person;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Auth\PortalPerson;
use App\Support\Forms\Forms;
use App\Support\Mail\Composer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Legal guardians fill in and confirm the person forms of their children (SF13–SF16, SF22) and maintain the family's
 * emergency contacts (FD01–FD02).
 */
class PortalFormController extends Controller
{
    public function __construct(private readonly Forms $forms) {}

    public function edit(Request $request, string $child, string $form): View
    {
        [$person, $model] = [$this->child($request, $child), $this->form($form)];
        abort_if($this->forms->status($model, $person) === 'not_applicable', 404);
        $this->forms->logView($model, $person);
        if ($person->is($this->guardian($request)) && $this->readOnly($person, $model)) {
            return view('portal.forms.read', ['child' => $person, 'form' => $model, 'values' => $this->forms->current($model, $person)->values ?? []]);
        }

        return view('portal.forms.edit', [
            'child' => $person,
            'form' => $model,
            'values' => old('values', $this->forms->prefill($model, $person)),
            'persons' => $model->fields->where('type', 'person')->mapWithKeys(fn (FormField $f) => [$f->key => $this->forms->personOptions($model, $f, $person, $this->guardian($request))])->all(),
            'status' => $this->forms->status($model, $person),
            'current' => $this->forms->current($model, $person),
            'emergency' => $model->family_emergency_contacts ? $this->forms->emergencyContacts($person) : [],
            'history' => FormResponse::query()->where('form_id', $model->id)->where('person_id', $person->id)->latest('id')->limit(20)->get(),
        ]);
    }

    private function readOnly(Person $person, Form $form): bool
    {
        return $form->family_emergency_contacts || ($person->ageAt() ?? 18) < 18;
    }

    public function update(Request $request, string $child, string $form): RedirectResponse
    {
        [$person, $model] = [$this->child($request, $child), $this->form($form)];
        abort_if($this->forms->status($model, $person) === 'not_applicable', 404);
        $request->merge(['values' => $this->forms->normalise($model, (array) $request->input('values', []), (array) $request->input('other', []))]);
        $data = $request->validate($this->forms->rules($model, $person));
        $response = $this->forms->save($model, $person, (array) ($data['values'] ?? []), $this->guardian($request));
        $this->thank($request, $model, $person, $response);

        return redirect()->route('portal.child', $person->public_id)->with('status', __('forms.saved', ['form' => $model->label()]));
    }

    public function confirm(Request $request, string $child, string $form): RedirectResponse
    {
        [$person, $model] = [$this->child($request, $child), $this->form($form)];
        $current = $this->forms->current($model, $person);
        abort_if($current === null, 404);
        // Confirming unchanged data only works if all required fields are still filled.
        validator(['values' => $this->forms->prefill($model, $person)], $this->forms->rules($model, $person))->validate();
        $response = $this->forms->save($model, $person, $this->forms->prefill($model, $person), $this->guardian($request), action: 'form.confirmed_unchanged');
        $this->thank($request, $model, $person, $response);

        return redirect()->route('portal.child', $person->public_id)->with('status', __('forms.confirmed', ['form' => $model->label()]));
    }

    /** Thank-you mail for the safety sheet; the summary only if the legal guardian asks for it (SF18, SF19). */
    private function thank(Request $request, Form $form, Person $child, FormResponse $response): void
    {
        $guardian = $this->guardian($request);
        if (! $form->family_emergency_contacts || $guardian->is($child)) {
            return;
        }
        $summary = $request->input('summary') === 'without_numbers';
        $mail = app(Composer::class)->queue('safety_sheet_thanks', $guardian, ['children' => [$child]], attachments: $summary ? [['form_response_id' => $response->id]] : null);
        if ($summary && $mail !== null) {
            Audit::record('form.summary_sent', $child, null, ['form' => $form->key]);
        }
    }

    public function emergencyContacts(Request $request, string $household): View
    {
        $model = $this->household($request, $household);

        return view('portal.forms.emergency', ['household' => $model, 'guardians' => $this->forms->orderedGuardians($model)]);
    }

    public function saveEmergencyContacts(Request $request, string $household): RedirectResponse
    {
        $model = $this->household($request, $household);
        $data = $request->validate([
            'order' => ['nullable', 'array', 'max:10'],
            'order.*' => ['integer', 'min:1', 'max:10'],
            'contacts' => ['nullable', 'array', 'max:5'],
            'contacts.*.name' => ['nullable', 'string', 'max:120'],
            'contacts.*.relation' => ['nullable', 'string', 'max:60'],
            'contacts.*.phone' => ['nullable', 'string', 'max:40'],
        ]);
        $model->emergencyContacts()->get()->each->delete();
        foreach (array_values(array_filter((array) ($data['contacts'] ?? []), fn ($c) => ! empty($c['name']) && ! empty($c['phone']))) as $i => $contact) {
            $model->emergencyContacts()->create($contact + ['sort' => $i]);
        }

        $this->forms->orderGuardians($model, array_map('intval', (array) ($data['order'] ?? [])));

        return redirect()->route('portal')->with('status', __('forms.emergency_saved'));
    }

    private function guardian(Request $request): Person
    {
        /** @var User $user */
        $user = $request->user();
        $person = PortalPerson::for($request);
        abort_if($person === null, 403);

        return $person;
    }

    private function child(Request $request, string $publicId): Person
    {
        $person = $this->guardian($request);

        // Children may see their safety sheet but not change it (SF20); other own forms are changed by adults only.
        if ($person->public_id === $publicId) {
            abort_if($this->readOnly($person, $this->form((string) $request->route('form'))) && ! $request->isMethod('GET'), 403);

            return $person;
        }

        return $person->wards()->where('public_id', $publicId)->firstOrFail();
    }

    private function form(string $key): Form
    {
        return Form::query()->where('key', $key)->where('kind', 'person')->where('active', true)->with('fields')->firstOrFail();
    }

    private function household(Request $request, string $publicId): Household
    {
        return $this->guardian($request)->households()->wherePivot('role', 'guardian')->where('households.public_id', $publicId)->firstOrFail();
    }
}
