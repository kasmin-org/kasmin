<?php

namespace App\Support\Forms;

use App\Models\Form;
use App\Models\FormField;
use App\Models\FormResponse;
use App\Support\Api\ApiContext;
use App\Support\Auth\Permissions;
use Illuminate\Validation\ValidationException;

/**
 * Who may edit forms must not get access to health data that way: once there are answers, only people with access to
 * the safety sheets may make a confidential field less confidential, rename or remove it, turn the safety sheet
 * into an ordinary form or delete it (SF06, SF20).
 */
class SensitivityGuard
{
    public static function field(FormField $field, bool $deleting = false): void
    {
        $was = in_array($field->getOriginal('sensitivity'), ['health', 'identity'], true);
        if ($was && ($deleting || ! $field->isConfidential() || $field->isDirty(['key', 'form_id']))) {
            self::check((int) $field->getOriginal('form_id'));
        }
    }

    public static function form(Form $form, bool $deleting = false): void
    {
        if ($form->getOriginal('family_emergency_contacts') && ($deleting || ! $form->family_emergency_contacts)) {
            self::check($form->id);
        }
    }

    private static function check(int $formId): void
    {
        if (! FormResponse::query()->where('form_id', $formId)->exists()) {
            return;
        }
        $allowed = match (true) {
            ApiContext::token() !== null => ApiContext::allows('health:write'),
            auth()->check() => Permissions::current('safety', 'edit'),
            default => true, // console and installation
        };
        if (! $allowed) {
            throw ValidationException::withMessages(['fields' => __('forms.sensitivity_locked')]);
        }
    }
}
