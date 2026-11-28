<?php

namespace App\Support\Forms;

use App\Casts\OneText;
use App\Models\Form;
use App\Models\FormField;
use App\Support\Audit\Audit;
use App\Support\Records\Records;
use App\Support\Records\UniqueName;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Form definitions as JSON, for exchange between installations and editing by API and MCP (SF09). Contains no answers.
 *
 * The file carries the internal keys of the form and its fields (B8): ids differ between installations, the keys stay
 * the same. On import a form is found by its key, otherwise by its exact name; fields are found by their key, a field
 * without a key is new (its key is made from the label).
 */
class FormDefinitions
{
    public const FORMAT = 'kasmin-form/1';

    private const FORM = ['key', 'kind', 'name', 'description', 'applies_until_age', 'confirm_every_days', 'family_emergency_contacts', 'active'];

    private const KEY = '/^[A-Za-z0-9_-]+$/';

    private const FIELD = ['key', 'type', 'label', 'help', 'options', 'required', 'scope', 'sensitivity', 'carry_over', 'list_use'];

    /** @return array<string, mixed> */
    public function export(Form $form): array
    {
        return ['format' => self::FORMAT] + $form->only(self::FORM) + ['fields' => $form->fields()->get()->map(fn (FormField $f) => $f->only(self::FIELD))->values()->all()];
    }

    /**
     * Creates the form or replaces the definition of the form (new version; answers stay): $into, else the form with the
     * key of the file, else the form with its exact name. Fields that are not in the definition are removed.
     *
     * @param  array<string, mixed>  $definition
     * @param  string|null  $keyForNew  key of a new form when the file has none (the address of older API clients)
     */
    public function import(array $definition, ?Form $into = null, ?string $keyForNew = null): Form
    {
        $definition = OneText::collapse($definition, ['name', 'description', 'fields.*.label', 'fields.*.help', 'fields.*.options.choices.*', 'fields.*.options.fields.*']);
        $data = validator($definition, $this->rules())->validate();
        $form = $into ?? (isset($data['key']) ? Form::query()->where('key', $data['key'])->first() : null)
            ?? Records::findByName(Form::query()->where('kind', $data['kind']), (string) $data['name'], 'name');
        validator(['name' => $data['name']], ['name' => [new UniqueName(Form::query(), $form?->getKey())]])->validate();

        return DB::transaction(function () use ($data, $form, $keyForNew) {
            $attributes = array_intersect_key($data, array_flip(self::FORM));
            unset($attributes['key']); // the key of a form never changes
            if ($form === null) {
                $key = $data['key'] ?? ($keyForNew !== null && preg_match(self::KEY, $keyForNew) === 1 ? $keyForNew : null);
                $form = Form::query()->create($attributes + ['key' => $key !== null && Form::query()->where('key', $key)->doesntExist() ? $key : null, 'sort' => (int) Form::query()->max('sort') + 10]);
            } else {
                unset($attributes['kind']); // answers of one kind cannot become answers of another kind
                $form->update($attributes + ['version' => $form->version + 1]);
            }
            foreach ($form->fields()->whereNotIn('key', array_values(array_filter(array_column($data['fields'], 'key'))))->get() as $removed) {
                $removed->delete();
            }
            foreach (array_values($data['fields']) as $i => $field) {
                $values = array_intersect_key($field, array_flip(self::FIELD)) + ['options' => null, 'help' => null, 'list_use' => null];
                $existing = isset($field['key']) ? $form->fields()->where('key', $field['key'])->first() : null;
                $existing === null ? $form->fields()->create($values + ['sort' => ($i + 1) * 10]) : $existing->update($values + ['sort' => ($i + 1) * 10]);
            }
            Audit::record('form.definition_imported', $form, null, ['fields' => count($data['fields'])]);

            return $form->load('fields');
        });
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        $key = ['sometimes', 'nullable', 'string', 'max:64', 'regex:'.self::KEY];

        return [
            'format' => ['required', Rule::in([self::FORMAT])],
            'key' => $key,
            'kind' => ['required', Rule::in(array_keys((array) __('admin.form.kinds')))],
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'applies_until_age' => ['nullable', 'integer', 'min:1', 'max:120'],
            'confirm_every_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'family_emergency_contacts' => ['boolean'], 'active' => ['boolean'],
            'fields' => ['required', 'array', 'min:1', 'max:200'],
            'fields.*.key' => [...$key, 'distinct'],
            'fields.*.type' => ['required', Rule::in(FormField::TYPES)],
            'fields.*.label' => ['required', 'string', 'max:500'],
            'fields.*.help' => ['nullable', 'string', 'max:2000'],
            'fields.*.options' => ['nullable', 'array:choices,other,list,fields,format,number,min,max'],
            'fields.*.options.choices' => ['nullable', 'array', 'max:200'],
            'fields.*.options.choices.*' => ['string', 'max:200'],
            'fields.*.options.fields' => ['nullable', 'array', 'max:10'],
            'fields.*.options.fields.*' => ['string', 'max:200'],
            'fields.*.options.other' => ['nullable', 'boolean'], 'fields.*.options.number' => ['nullable', 'boolean'],
            'fields.*.options.list' => ['nullable', 'string', 'max:64'], 'fields.*.options.format' => ['nullable', 'string', 'max:64'],
            'fields.*.options.min' => ['nullable', 'regex:/^-?\d+(\.\d+)?$|^\d{4}-\d{2}-\d{2}$/'], 'fields.*.options.max' => ['nullable', 'regex:/^-?\d+(\.\d+)?$|^\d{4}-\d{2}-\d{2}$/'],
            'fields.*.required' => ['boolean'], 'fields.*.carry_over' => ['boolean'],
            'fields.*.scope' => ['string', Rule::in(array_keys((array) __('admin.form.scopes')))],
            'fields.*.sensitivity' => ['required', Rule::in(['normal', 'health', 'identity'])],
            'fields.*.list_use' => ['nullable', 'array'], 'fields.*.list_use.*' => ['string', 'max:32'],
        ];
    }
}
