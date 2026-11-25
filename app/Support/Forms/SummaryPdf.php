<?php

namespace App\Support\Forms;

use App\Models\FormField;
use App\Models\FormResponse;
use App\Models\OutgoingMail;
use App\Models\Person;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;

/**
 * Summary of a confirmed safety sheet that a legal guardian sends to themselves (SF19): everything except insurance
 * and identity numbers. Made when the mail is sent, so the values are never stored with the mail.
 */
class SummaryPdf
{
    /** Hours after the confirmation in which the summary can be sent. */
    public const HOURS = 48;

    public function __construct(private readonly Forms $forms) {}

    /** @return array{name: string, content: string}|null null if the receiver may not get it (any more) */
    public function for(OutgoingMail $mail, int $responseId): ?array
    {
        $response = FormResponse::query()->with('form.fields')->find($responseId);
        $receiver = $mail->person_id !== null ? Person::query()->find($mail->person_id) : null;
        $person = $response !== null ? Person::query()->find($response->person_id) : null;
        $form = $response?->form;
        if ($response === null || $form === null || $receiver === null || $person === null
            || $response->confirmed_by_id !== $receiver->id
            || $response->created_at->lt(now()->subHours(self::HOURS))
            || ! $person->guardians()->whereKey($receiver->id)->exists()
            || ! $receiver->contactPoints()->where('type', 'email')->where('value', $mail->to_address)->exists()) {
            return null;
        }
        $previous = app()->getLocale();
        app()->setLocale($receiver->locale ?: $previous);
        try {
            $rows = $form->fields->reject(fn (FormField $f) => $f->sensitivity === 'identity')
                ->map(fn (FormField $f) => ['label' => $f->labelText(), 'value' => FormValue::text($f, $response->values[$f->key] ?? null) ?? '–'])->values()->all();
            $content = Pdf::loadView('pdf.form-summary', ['form' => $form, 'person' => $person, 'response' => $response, 'rows' => $rows, 'contacts' => $this->forms->emergencyContacts($person)])->setPaper('a4')->output();

            return ['name' => Str::slug($form->label().' '.$person->first_name).'.pdf', 'content' => $content];
        } finally {
            app()->setLocale($previous);
        }
    }
}
