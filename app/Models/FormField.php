<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Forms\ChoiceLists;
use App\Support\Forms\SensitivityGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $form_id
 * @property string $key
 * @property string $type
 * @property array<string, string> $label
 * @property array<string, string>|null $help
 * @property array<string, mixed>|null $options
 * @property bool $required
 * @property string $scope
 * @property string $sensitivity
 * @property bool $carry_over
 * @property list<string>|null $list_use
 */
class FormField extends Model
{
    use Auditable;

    public const TYPES = ['text', 'textarea', 'select', 'multiselect', 'yes_no', 'number', 'date', 'year_or_no', 'phone', 'email', 'none_or_text', 'repeater', 'person', 'consent'];

    /** Value of a "none" answer (distinct from "not answered", SF03). */
    public const NONE = ':none';

    /** Start of a free answer to a choice with "other" (SF02). */
    public const OTHER = ':other:';

    protected $guarded = ['id'];

    protected $attributes = ['sensitivity' => 'normal', 'carry_over' => true, 'required' => false, 'scope' => 'participant'];

    protected function casts(): array
    {
        return ['label' => 'array', 'help' => 'array', 'options' => 'array', 'required' => 'boolean', 'carry_over' => 'boolean', 'list_use' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn (FormField $field) => SensitivityGuard::field($field));
        static::deleting(fn (FormField $field) => SensitivityGuard::field($field, deleting: true));
    }

    /** @return BelongsTo<Form, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /** Confidential fields never appear in mails, notifications or logs (FB08). */
    public function isConfidential(): bool
    {
        return in_array($this->sensitivity, ['health', 'identity'], true);
    }

    public function labelText(): string
    {
        return $this->label[app()->getLocale()] ?? $this->label['en'] ?? $this->key;
    }

    public function helpText(): ?string
    {
        return $this->help[app()->getLocale()] ?? $this->help['en'] ?? null;
    }

    /** @return array<string, string> choice value => label in the current language */
    public function choices(): array
    {
        $choices = ChoiceLists::get((string) ($this->options['list'] ?? ''));
        foreach ((array) ($this->options['choices'] ?? []) as $value => $labels) {
            $choices[(string) $value] = is_array($labels) ? (string) ($labels[app()->getLocale()] ?? $labels['en'] ?? $value) : (string) $labels;
        }

        return $choices;
    }

    public function allowsOther(): bool
    {
        return (bool) ($this->options['other'] ?? false);
    }

    /** @return list<string> sub-field keys of a repeater */
    public function subFields(): array
    {
        return array_map('strval', array_keys((array) ($this->options['fields'] ?? [])));
    }

    public function subFieldLabel(string $key): string
    {
        $labels = (array) (($this->options['fields'] ?? [])[$key] ?? []);

        return (string) ($labels[app()->getLocale()] ?? $labels['en'] ?? $key);
    }
}
