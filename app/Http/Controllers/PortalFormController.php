<?php

namespace App\Http\Controllers;

use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Household;
use App\Models\Person;
use App\Models\User;
use App\Support\Auth\PortalPerson;
use App\Support\Forms\Forms;
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
        $data = $request->validate($this->forms->rules($model));
        $this->forms->save($model, $person, (array) ($data['values'] ?? []), $this->guardian($request));

        return redirect()->route('portal.child', $person->public_id)->with('status', __('forms.saved', ['form' => $model->label()]));
    }

    public function confirm(Request $request, string $child, string $form): RedirectResponse
    {
        [$person, $model] = [$this->child($request, $child), $this->form($form)];
        $current = $this->forms->current($model, $person);
        abort_if($current === null, 404);
        // Confirming unchanged data only works if all required fields are still filled.
        validator(['values' => $this->forms->prefill($model, $person)], $this->forms->rules($model))->validate();
        $this->forms->save($model, $person, $this->forms->prefill($model, $person), $this->guardian($request), action: 'form.confirmed_unchanged');

        return redirect()->route('portal.child', $person->public_id)->with('status', __('forms.confirmed', ['form' => $model->label()]));
    }

    public function emergencyContacts(Request $request, string $household): View
    {
        return view('portal.forms.emergency', ['household' => $this->household($request, $household)]);
    }

    public function saveEmergencyContacts(Request $request, string $household): RedirectResponse
    {
        $model = $this->household($request, $household);
        $data = $request->validate([
            'contacts' => ['nullable', 'array', 'max:5'],
            'contacts.*.name' => ['nullable', 'string', 'max:120'],
            'contacts.*.relation' => ['nullable', 'string', 'max:60'],
            'contacts.*.phone' => ['nullable', 'string', 'max:40'],
        ]);
        $model->emergencyContacts()->get()->each->delete();
        foreach (array_values(array_filter((array) ($data['contacts'] ?? []), fn ($c) => ! empty($c['name']) && ! empty($c['phone']))) as $i => $contact) {
            $model->emergencyContacts()->create($contact + ['sort' => $i]);
        }

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
