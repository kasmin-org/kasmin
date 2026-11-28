<?php

namespace App\Models;

use App\Casts\OneText;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasInternalKey;
use App\Support\Forms\ChoiceLists;
use App\Support\Forms\SensitivityGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $form_id
 * @property string $key
 * @property string $type
 * @property string|null $label
 * @property string|null $help
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
    use HasInternalKey;

    public const TYPES = ['text', 'textarea', 'select', 'multiselect', 'yes_no', 'number', 'date', 'year_or_no', 'phone', 'email', 'none_or_text', 'repeater', 'person', 'consent'];

    /** Value of a "none" answer (distinct from "not answered", SF03). */
    public const NONE = ':none';

    /** Start of a free answer to a choice with "other" (SF02). */
    public const OTHER = ':other:';

    protected $guarded = ['id'];

    protected $attributes = ['sensitivity' => 'normal', 'carry_over' => true, 'required' => false, 'scope' => 'participant'];

    protected function casts(): array
    {
        return ['label' => OneText::class, 'help' => OneText::class, 'options' => 'array', 'required' => 'boolean', 'carry_over' => 'boolean', 'list_use' => 'array'];
    }

    protected static function booted(): void
    {
        // One text per choice and per column; standard forms of the software come with both languages.
        static::saving(function (FormField $field): void {
            $options = $field->options;
            foreach (['choices', 'fields'] as $part) {
                if (is_array($options[$part] ?? null)) {
                    $options[$part] = array_map(fn ($text) => (string) OneText::of($text), $options[$part]);
                    $field->options = $options;
                }
            }
        });
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

    /** The key of a field is made from its label once; answers and placeholders refer to it. */
    protected function internalKeySource(): string
    {
        return (string) $this->label;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function internalKeyScope(Builder $query): Builder
    {
        return $query->where('form_id', $this->form_id);
    }

    public function labelText(): string
    {
        return $this->label ?: $this->key;
    }

    public function helpText(): ?string
    {
        return $this->help ?: null;
    }

    /** @return array<string, string> choice value => label */
    public function choices(): array
    {
        $choices = ChoiceLists::get((string) ($this->options['list'] ?? ''));
        foreach ((array) ($this->options['choices'] ?? []) as $value => $labels) {
            $choices[(string) $value] = OneText::of($labels) ?? (string) $value;
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
        return OneText::of(($this->options['fields'] ?? [])[$key] ?? null) ?: $key;
    }
}
