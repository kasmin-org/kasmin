<?php

namespace App\Http\Controllers\Api;

use App\Models\Form;
use App\Models\FormField;
use App\Models\FormResponse;
use App\Support\Api\ApiContext;
use App\Support\Forms\FormDefinitions;
use App\Support\Forms\Forms;
use App\Support\Records\Records;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Forms such as the safety sheet (SF28). A form is addressed by its id or its exact name; the fields of a form by
 * their key, which is made from the label once and never changes (values and placeholders refer to it). Status: people:read. Values: people:read, but confidential fields only with
 * health:read (writing: health:write); every read of confidential values is logged.
 */
class FormsController extends ApiController
{
    public function __construct(private readonly Forms $forms, private readonly FormDefinitions $definitions) {}

    /** All active forms with their fields. */
    public function index(): JsonResponse
    {
        $this->scope('people:read');
        $forms = Form::query()->where('active', true)->with('fields')->orderBy('sort')->get();

        return response()->json(['data' => $forms->map(fn (Form $f) => ['id' => (int) $f->getKey(), 'kind' => $f->kind, 'name' => $f->label(), 'version' => $f->version, 'fields' => $f->fields->map(fn (FormField $x) => [
            'id' => (int) $x->getKey(), 'key' => $x->key, 'type' => $x->type, 'label' => $x->label, 'required' => $x->required, 'confidential' => $x->isConfidential(),
        ])->values()->all()])->values()]);
    }

    /** Definition of a form as JSON (no answers); the same format can be imported. */
    public function definition(string $form): JsonResponse
    {
        $this->scope('people:read');

        return response()->json(['data' => $this->definitions->export(Records::findOrFail(Form::query(), $form, 'form'))]);
    }

    /**
     * Replaces the definition of the form (new version; answers stay), or creates a form when no form has this id or
     * name. Making a confidential field less confidential, renaming or removing it needs health:write once there are
     * answers. Fields are matched by their key; a field without a key is new.
     */
    public function saveDefinition(Request $request, string $form): JsonResponse
    {
        $this->scope('settings:write');
        $existing = Records::find(Form::query(), $form, 'form');
        abort_if($existing === null && ctype_digit($form), 404, 'No form has this id.');
        $saved = $this->definitions->import($request->all(), $existing, $existing === null ? $form : null);

        return response()->json(['data' => $this->definitions->export($saved) + ['version' => $saved->version]]);
    }

    /** Status of all forms of a person (missing, expired, confirmed). */
    public function status(string $person): JsonResponse
    {
        $this->scope('people:read');
        $model = $this->person($person);
        $forms = Form::query()->where('kind', 'person')->where('active', true)->with('fields')->orderBy('sort')->get();

        return response()->json(['data' => $forms->map(fn (Form $f) => ['form' => (int) $f->getKey(), 'name' => $f->label(), 'status' => $this->forms->status($f, $model), 'confirmed_at' => $this->forms->current($f, $model)?->confirmed_at?->toIso8601String()])->values()]);
    }

    /** Values and versions of one form of a person. */
    public function show(string $person, string $form): JsonResponse
    {
        $this->scope('people:read');
        $model = $this->person($person);
        $f = $this->form($form);
        $status = $this->forms->status($f, $model);
        $health = ApiContext::allows('health:read');
        if ($status === 'not_applicable') {
            return response()->json(['data' => ['form' => (int) $f->getKey(), 'name' => $f->label(), 'status' => $status, 'values' => (object) [], 'versions' => []]]); // from the age limit on (SF20a)
        }
        if ($health) {
            $this->forms->logView($f, $model);
        }
        $visible = $f->fields->filter(fn (FormField $x) => $health || ! $x->isConfidential())->pluck('key')->all();
        $versions = FormResponse::query()->where('form_id', $f->id)->where('person_id', $model->id)->latest('id')->limit(50)->get();

        return response()->json(['data' => [
            'form' => (int) $f->getKey(), 'name' => $f->label(), 'status' => $status,
            'values' => (object) array_intersect_key($versions->first()->values ?? [], array_flip($visible)),
            'hidden' => $f->fields->pluck('key')->diff($visible)->values()->all(),
            'versions' => $versions->map(fn (FormResponse $r) => ['at' => $r->created_at->toIso8601String(), 'confirmed_at' => $r->confirmed_at?->toIso8601String(), 'changed_fields' => $health ? ($r->changed_keys ?? []) : array_values(array_intersect($r->changed_keys ?? [], $visible))])->values()->all(),
        ]]);
    }

    /** Saves a new version with the given values; the legal guardians have to confirm it in the portal. */
    public function update(Request $request, string $person, string $form): JsonResponse
    {
        $this->scope('people:write');
        $model = $this->person($person);
        $f = $this->form($form);
        abort_if($this->forms->status($f, $model) === 'not_applicable', 422, 'This form does not apply to this person.');
        $given = (array) $request->validate(['values' => ['required', 'array']])['values'];
        // A field is named by its key or its id.
        $keys = $f->fields->mapWithKeys(fn (FormField $x) => [(string) $x->getKey() => $x->key]);
        $given = collect($given)->mapWithKeys(fn ($value, $field) => [(string) ($keys->get((string) $field) ?? $field) => $value])->all();
        foreach ($f->fields as $field) {
            if (array_key_exists($field->key, $given) && $field->isConfidential()) {
                $this->scope('health:write');
            }
        }
        abort_if(array_diff(array_keys($given), $f->fields->pluck('key')->all()) !== [], 422, 'Unknown field.');
        $rules = array_filter($this->forms->rules($f, $model), fn (string $rule) => array_key_exists(explode('.', $rule)[1] ?? '', $given), ARRAY_FILTER_USE_KEY);
        validator(['values' => $given], $rules)->validate();
        $this->forms->save($f, $model, $given + ($this->forms->current($f, $model)->values ?? []), null, confirm: false, action: 'form.saved_by_api');

        return response()->json(['data' => ['form' => (int) $f->getKey(), 'name' => $f->label(), 'status' => $this->forms->status($f, $model)]]);
    }

    private function form(string $form): Form
    {
        return Records::findOrFail(Form::query()->where('kind', 'person')->where('active', true)->with('fields'), $form, 'form');
    }
}
