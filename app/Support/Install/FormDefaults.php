<?php

namespace App\Support\Install;

use App\Models\Form;

/**
 * Default forms installed with "German/English defaults": safety sheet and general information. Every field has
 * a help text (H02). Labels are stored in both languages, so the language can be changed later.
 */
class FormDefaults
{
    public function install(): void
    {
        $this->create('safety_sheet', ['de' => 'Sicherheitsblatt', 'en' => 'Safety sheet'], [
            'de' => 'Diese Angaben brauchen wir, damit wir gut auf Ihr Kind aufpassen können. Sie sind vertraulich und nur für wenige Personen sichtbar.',
            'en' => 'We need this information to take good care of your child. It is confidential and visible only to a few people.',
        ], appliesUntilAge: 16, confirmEveryDays: 365, emergency: true, sort: 10, fields: [
            ['insurance_carrier', 'text', ['de' => 'Krankenversicherung', 'en' => 'Health insurance'], ['de' => 'Name der Krankenkasse oder Versicherung.', 'en' => 'Name of the health insurance.'], 'identity', ['insurance']],
            ['insurance_number', 'text', ['de' => 'Versicherungsnummer des Kindes', 'en' => 'Insurance number of the child'], ['de' => 'Wird nur im Notfall für Ärztin oder Arzt bzw. Krankenhaus verwendet.', 'en' => 'Only used in an emergency for a doctor or hospital.'], 'identity', ['insurance']],
            ['co_insured_with', 'text', ['de' => 'Mitversichert bei', 'en' => 'Co-insured with'], ['de' => 'Name und Beziehung, z. B. eine erziehungsberechtigte Person.', 'en' => 'Name and relation, e.g. a legal guardian.'], 'identity', ['insurance']],
            ['diet', 'select', ['de' => 'Ernährung', 'en' => 'Diet'], ['de' => 'Für die Küche.', 'en' => 'For the kitchen.'], 'health', ['kitchen'], ['choices' => [
                'none' => ['de' => 'Keine Einschränkung', 'en' => 'No restrictions'],
                'vegetarian' => ['de' => 'Vegetarisch', 'en' => 'Vegetarian'],
                'vegan' => ['de' => 'Vegan', 'en' => 'Vegan'],
                'no_pork' => ['de' => 'Kein Schweinefleisch', 'en' => 'No pork'],
                'other' => ['de' => 'Andere (bitte bei Unverträglichkeiten angeben)', 'en' => 'Other (please describe under intolerances)'],
            ]], true],
            ['intolerances', 'none_or_text', ['de' => 'Nahrungsmittel-Unverträglichkeiten', 'en' => 'Food intolerances'], ['de' => 'Wählen Sie „Keine“, wenn es keine gibt.', 'en' => 'Choose "None" if there are none.'], 'health', ['kitchen', 'medical'], null, true],
            ['allergies', 'none_or_text', ['de' => 'Bekannte Allergien', 'en' => 'Known allergies'], ['de' => 'Auch Insektenstiche, Pollen, Medikamente.', 'en' => 'Also insect stings, pollen, medication.'], 'health', ['kitchen', 'medical'], null, true],
            ['allergy_severity', 'select', ['de' => 'Schwere der Allergie', 'en' => 'Severity of the allergy'], ['de' => 'Nur ausfüllen, wenn eine Allergie besteht.', 'en' => 'Only if there is an allergy.'], 'health', ['medical'], ['choices' => [
                'mild' => ['de' => 'Leicht', 'en' => 'Mild'],
                'severe' => ['de' => 'Schwer', 'en' => 'Severe'],
                'emergency_kit' => ['de' => 'Notfallmedikament vorhanden', 'en' => 'Emergency medication available'],
            ]]],
            ['medication', 'repeater', ['de' => 'Dauerhafte Medikation', 'en' => 'Permanent medication'], ['de' => 'Name, Dosis, Zeitpunkt und wer es gibt. Leer lassen, wenn keine.', 'en' => 'Name, dose, time and who gives it. Leave empty if none.'], 'health', ['medical'], ['fields' => [
                'name' => ['de' => 'Medikament', 'en' => 'Medication'], 'dose' => ['de' => 'Dosis', 'en' => 'Dose'],
                'time' => ['de' => 'Zeitpunkt', 'en' => 'Time'], 'given_by' => ['de' => 'Wer gibt es', 'en' => 'Given by'],
            ]]],
            ['medical_needs', 'none_or_text', ['de' => 'Besondere medizinische Bedürfnisse', 'en' => 'Special medical needs'], ['de' => 'Z. B. Epilepsie, Asthma, Diabetes.', 'en' => 'E.g. epilepsy, asthma, diabetes.'], 'health', ['medical'], null, true],
            ['tetanus', 'year_or_no', ['de' => 'Tetanus-Impfung', 'en' => 'Tetanus vaccination'], ['de' => 'Jahr der letzten Impfung, „nein“ oder „unbekannt“.', 'en' => 'Year of the last vaccination, "no" or "unknown".'], 'health', ['medical'], null, true],
            ['tbe', 'year_or_no', ['de' => 'FSME-Impfung (Zecken)', 'en' => 'Tick-borne encephalitis vaccination'], ['de' => 'Jahr der letzten Impfung, „nein“ oder „unbekannt“.', 'en' => 'Year of the last vaccination, "no" or "unknown".'], 'health', ['medical'], null, true],
            ['swimmer', 'yes_no', ['de' => 'Mein Kind kann schwimmen', 'en' => 'My child can swim'], ['de' => 'Wichtig für Ausflüge ans Wasser.', 'en' => 'Important for trips to the water.'], 'normal', ['emergency'], null, true],
            ['photos_internal', 'consent', ['de' => 'Fotos und Videos für interne Zwecke', 'en' => 'Photos and videos for internal use'], ['de' => 'Z. B. Fotoalbum für Teilnehmende und Erziehungsberechtigte.', 'en' => 'E.g. a photo album for participants and legal guardians.'], 'normal', [], null, true],
            ['photos_public', 'consent', ['de' => 'Veröffentlichung von Fotos', 'en' => 'Publishing photos'], ['de' => 'Auf der Website und in sozialen Medien.', 'en' => 'On the website and in social media.'], 'normal', [], null, true],
            ['notes', 'textarea', ['de' => 'Sonstiges für dieses Jahr', 'en' => 'Other notes for this year'], ['de' => 'Wird nicht ins nächste Jahr übernommen.', 'en' => 'Not carried over to the next year.'], 'health', ['medical'], null, false, false],
        ]);

        $this->create('general_information', ['de' => 'Allgemeine Angaben', 'en' => 'General information'], [
            'de' => 'Nicht vertrauliche Angaben, die wir für Veranstaltungen brauchen.',
            'en' => 'Non-confidential information we need for events.',
        ], appliesUntilAge: null, confirmEveryDays: 365, emergency: false, sort: 20, fields: [
            ['tshirt_size', 'select', ['de' => 'T-Shirt-Größe', 'en' => 'T-shirt size'], ['de' => 'Kinder wachsen – bitte jedes Jahr prüfen.', 'en' => 'Children grow – please check every year.'], 'normal', [], ['choices' => array_combine(
                ['116', '128', '140', '152', '164', 'XS', 'S', 'M', 'L', 'XL', 'XXL'],
                array_map(fn ($s) => ['de' => $s, 'en' => $s], ['116', '128', '140', '152', '164', 'XS', 'S', 'M', 'L', 'XL', 'XXL']),
            )]],
            ['needs_new_tshirts', 'yes_no', ['de' => 'Ich brauche neue T-Shirts (keine vorhanden oder abgetragen)', 'en' => 'I need new T-shirts (I have none or they are worn out)'], ['de' => 'Damit wir genug bestellen.', 'en' => 'So we order enough.'], 'normal', []],
        ]);
    }

    /**
     * @param  array<string, string>  $name
     * @param  array<string, string>  $description
     * @param  list<array<int, mixed>>  $fields  [key, type, label, help, sensitivity, list_use, options?, required?, carry_over?]
     */
    private function create(string $key, array $name, array $description, ?int $appliesUntilAge, ?int $confirmEveryDays, bool $emergency, int $sort, array $fields): void
    {
        if (Form::query()->where('key', $key)->exists()) {
            return;
        }
        $form = Form::query()->create([
            'key' => $key, 'name' => $name, 'description' => $description, 'applies_until_age' => $appliesUntilAge,
            'confirm_every_days' => $confirmEveryDays, 'family_emergency_contacts' => $emergency, 'sort' => $sort,
        ]);
        foreach ($fields as $i => $f) {
            $form->fields()->create([
                'key' => $f[0], 'type' => $f[1], 'label' => $f[2], 'help' => $f[3], 'sensitivity' => $f[4], 'list_use' => $f[5],
                'options' => $f[6] ?? null, 'required' => (bool) ($f[7] ?? false), 'carry_over' => (bool) ($f[8] ?? true), 'sort' => ($i + 1) * 10,
            ]);
        }
    }
}
