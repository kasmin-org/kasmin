<?php

namespace App\Support\Api;

use App\Casts\OneText;
use App\Models\Automation;
use App\Models\EventRole;
use App\Models\Form;
use App\Models\MailTemplate;
use App\Models\Participation;
use App\Models\Role;
use App\Support\Automations\Actions\Custom;
use App\Support\Automations\InstallmentPosition;
use App\Support\People\GroupMembers;
use App\Support\Records\Records;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Checks changes to an automation that come in via the API, with the rules of the automation form in the admin
 * interface (AP01). Key, action and "standard" cannot be changed.
 *
 * Mail templates, forms and approver roles are given by their id or exact name (B8; the internal key of older versions
 * is still accepted). The automation stores the internal key of the template, form or role, so standard automations
 * keep working; the API shows ids (see Presenter::automation()).
 */
class AutomationInput
{
    public const FIELDS = ['name', 'active', 'mode', 'max_items', 'approvals_needed', 'approver_roles', 'trigger', 'trigger_config', 'config'];

    /** Who approves and when; see protect(). */
    public const APPROVAL_FIELDS = ['mode', 'max_items', 'approvals_needed', 'approver_roles'];

    /** Setting of the trigger => triggers that have it. */
    private const TRIGGER_KEYS = [
        'time' => ['daily', 'weekly', 'monthly', 'once'], 'weekday' => ['weekly'], 'day' => ['monthly'], 'date' => ['once'],
        'statuses' => ['on_status'], 'roles' => ['on_status'], 'group' => ['on_group_joined', 'on_group_left'], 'fields' => ['on_person_changed'],
        'days' => ['before_event', 'after_event'], 'series' => ['before_event', 'after_event', 'on_status'],
    ];

    /** Settings of the standard actions; actions that are not listed have none. */
    private const CONFIG_KEYS = [
        'send_template' => ['template', 'statuses', 'to'], 'invoice_run' => ['installment', 'send'], 'invoice_corrections' => ['send'],
        'membership_invoices' => ['send', 'year'], 'payment_followup' => ['days'], 'waitlist_offer' => ['offer_days'], 'group_waitlist_offer' => ['offer_days'],
        'safety_sheet_reminder' => ['form', 'interval_days', 'max', 'window_days'], 'age_notice' => ['window_days', 'age'], 'start_season' => ['role', 'next_role'],
        'custom' => ['target', 'conditions', 'once', 'steps'],
    ];

    private const CHANGE_TRIGGERS = ['on_status', 'on_group_joined', 'on_group_left', 'on_person_created', 'on_person_changed'];

    private const EVENT_TRIGGERS = ['manual', 'before_event', 'after_event', 'on_place_freed'];

    /** Settings of a step => step types that have it; all but "to" are required. */
    private const STEP_KEYS = [
        'send_mail' => ['template', 'to'], 'add_group' => ['group_id'], 'remove_group' => ['group_id'], 'set_status' => ['status'],
        'notify_team' => ['text'], 'webhook' => ['name'], 'invoice' => ['description', 'amount'],
    ];

    /**
     * @param  array<array-key, mixed>  $input
     * @return array<string, mixed> attributes to store
     *
     * @throws ValidationException
     */
    public static function check(Automation $automation, array $input): array
    {
        self::known(array_keys($input), self::FIELDS, 'automation');
        if (is_array($input['approver_roles'] ?? null)) {
            $admins = Role::query()->where('is_admin', true);
            $input['approver_roles'] = array_map(fn ($role, $i) => is_scalar($role) ? Records::require($admins, $role, "approver_roles.{$i}")->key : $role, $input['approver_roles'], array_keys($input['approver_roles']));
        }
        $data = Validator::make(OneText::collapse($input, ['name']), [
            'name' => ['sometimes', 'string', 'max:200'],
            'active' => ['sometimes', 'boolean'],
            'mode' => ['sometimes', Rule::in(Automation::MODES)],
            'max_items' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'approvals_needed' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'approver_roles' => ['sometimes', 'nullable', 'array'],
            'approver_roles.*' => ['string', 'distinct', Rule::in(Role::query()->where('is_admin', true)->pluck('key')->all())],
            'trigger' => ['sometimes', Rule::in(Automation::TRIGGERS)],
            'trigger_config' => ['sometimes', 'nullable', 'array'],
            'config' => ['sometimes', 'nullable', 'array'],
        ])->validate();
        if (array_key_exists('approver_roles', $data)) {
            $data['approver_roles'] = array_values($data['approver_roles'] ?? []) ?: null;
        }
        $trigger = (string) ($data['trigger'] ?? $automation->trigger);
        if (array_key_exists('trigger', $data) || array_key_exists('trigger_config', $data)) {
            $given = array_key_exists('trigger_config', $data);
            $data['trigger_config'] = self::triggerConfig($trigger, (array) ($given ? $data['trigger_config'] : $automation->trigger_config), $given);
        }
        if (array_key_exists('config', $data) || ($automation->action === 'custom' && array_key_exists('trigger', $data))) {
            $config = (array) (array_key_exists('config', $data) ? $data['config'] : $automation->config);
            $data['config'] = $automation->action === 'custom' ? self::customConfig($config, $trigger) : self::standardConfig($automation->action, $config);
        }

        return $data;
    }

    /**
     * An AI assistant must not switch an approval off (X05, MI12): via MCP, a token without the scope
     * approvals:decide cannot change who approves and when (mode, max_items, approvals_needed, approver_roles), and
     * cannot change what an automation does or switch it on while it runs without approval (mode automatic).
     * Call it with the changes filled in and not yet saved.
     *
     * @throws AuthorizationException
     */
    public static function protect(Automation $automation): void
    {
        if (! ApiContext::viaMcp() || ApiContext::allows('approvals:decide')) {
            return;
        }
        if ($automation->isDirty(self::APPROVAL_FIELDS)) {
            throw new AuthorizationException('Via MCP, changing '.implode(', ', self::APPROVAL_FIELDS).' needs the scope approvals:decide.');
        }
        $unapproved = $automation->getOriginal('mode') === 'automatic'
            && ($automation->isDirty(['trigger', 'trigger_config', 'config']) || ($automation->isDirty('active') && $automation->active));
        if ($unapproved) {
            throw new AuthorizationException('This automation runs without approval. Via MCP, changing its trigger or settings or switching it on needs the scope approvals:decide.');
        }
    }

    /**
     * @param  array<array-key, mixed>  $config
     * @return array<string, mixed>|null
     */
    private static function triggerConfig(string $trigger, array $config, bool $given): ?array
    {
        $allowed = array_keys(array_filter(self::TRIGGER_KEYS, fn (array $triggers) => in_array($trigger, $triggers, true)));
        // Settings of the old trigger do not block a change of the trigger; what the new one needs must be given.
        self::known(array_keys($given ? $config : array_intersect_key($config, array_flip($allowed))), $allowed, 'trigger '.$trigger, 'trigger_config');
        $config = $given ? $config : array_intersect_key($config, array_flip($allowed));
        $rules = array_intersect_key([
            'time' => ['nullable', 'regex:/^\d{2}:\d{2}$/'],
            'weekday' => ['required', 'integer', 'between:1,7'],
            'day' => ['nullable', 'integer', 'between:1,31'],
            'date' => ['required', 'date'],
            'statuses' => ['required', 'array', 'min:1'],
            'roles' => ['nullable', 'array'],
            'group' => ['required', 'integer', 'exists:groups,id'],
            'fields' => ['required', 'array', 'min:1'],
            'days' => ['nullable', 'integer', 'min:0'],
            'series' => ['nullable', 'integer', 'exists:event_series,id'],
        ], array_flip($allowed)) + [
            'statuses.*' => ['string', 'distinct', Rule::in(Participation::STATUSES)],
            'roles.*' => ['string', 'distinct', Rule::in(EventRole::KEYS)],
            'fields.*' => ['string', 'distinct', Rule::in(['birth_date', 'first_name', 'last_name', 'gender'])],
        ];
        $valid = self::prefixed('trigger_config', $config, $rules);
        if (isset($valid['date'])) {
            $valid['date'] = Carbon::parse((string) $valid['date'])->toDateString();
        }
        foreach (['weekday', 'day', 'group', 'days', 'series'] as $number) {
            if (isset($valid[$number])) {
                $valid[$number] = (int) $valid[$number];
            }
        }
        $valid = array_filter($valid, fn ($v) => $v !== null && $v !== []);

        return $valid === [] ? null : $valid;
    }

    /**
     * @param  array<array-key, mixed>  $config
     * @return array<string, mixed>|null
     */
    private static function standardConfig(string $action, array $config): ?array
    {
        $allowed = self::CONFIG_KEYS[$action] ?? [];
        self::known(array_keys($config), $allowed, 'action '.$action, 'config');
        if (is_scalar($config['template'] ?? null)) {
            $config['template'] = Records::require(MailTemplate::query(), $config['template'], 'config.template')->key;
        }
        if (is_scalar($config['form'] ?? null)) {
            $config['form'] = Records::require(Form::query()->where('kind', 'person'), $config['form'], 'config.form')->key;
        }
        $count = ['nullable', 'integer', 'min:1', 'max:100000'];
        $rules = array_intersect_key([
            'template' => ['nullable', 'string', 'exists:mail_templates,key'],
            'statuses' => ['nullable', 'array'],
            'to' => ['nullable', Rule::in(['guardians', 'person', 'both'])],
            'installment' => ['nullable', 'string', 'max:32', 'regex:'.InstallmentPosition::PATTERN],
            'send' => ['nullable', 'boolean'],
            'days' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'offer_days' => $count, 'interval_days' => $count, 'max' => $count, 'window_days' => $count, 'age' => $count,
            'form' => ['nullable', 'string', 'exists:forms,key'],
            'role' => ['nullable', Rule::in(EventRole::KEYS)],
            'next_role' => ['nullable', Rule::in(EventRole::KEYS)],
        ], array_flip($allowed)) + ['statuses.*' => ['string', 'distinct', Rule::in(['registered', 'confirmed', 'offered', 'waitlist', 'attended'])]];
        $valid = self::prefixed('config', $config, $rules);
        foreach ($valid as $key => $value) {
            if ($key === 'send') {
                $valid[$key] = $value === null ? null : filter_var($value, FILTER_VALIDATE_BOOL);
            } elseif (is_numeric($value)) {
                $valid[$key] = (int) $value;
            }
        }
        $valid = array_filter($valid, fn ($v) => $v !== null && $v !== []);

        return $valid === [] ? null : $valid;
    }

    /**
     * An own automation: for whom, conditions and steps.
     *
     * @param  array<array-key, mixed>  $config
     * @return array<string, mixed>
     */
    private static function customConfig(array $config, string $trigger): array
    {
        self::known(array_keys($config), self::CONFIG_KEYS['custom'], 'action custom', 'config');
        foreach (is_array($config['steps'] ?? null) ? $config['steps'] : [] as $i => $step) {
            if (is_array($step) && is_scalar($step['template'] ?? null)) {
                $config['steps'][$i]['template'] = Records::require(MailTemplate::query(), $step['template'], "config.steps.{$i}.template")->key;
            }
        }
        $valid = self::prefixed('config', $config, [
            'target' => ['required', 'array:source,statuses,roles,group_id'],
            'target.source' => ['required', Rule::in(['subject', 'event', 'group', 'people'])],
            'target.statuses' => ['nullable', 'array'],
            'target.statuses.*' => ['string', 'distinct', Rule::in(Participation::STATUSES)],
            'target.roles' => ['nullable', 'array'],
            'target.roles.*' => ['string', 'distinct', Rule::in(EventRole::KEYS)],
            'target.group_id' => ['nullable', 'required_if:config.target.source,group', 'integer', 'exists:groups,id'],
            'conditions' => ['nullable', 'array:match,rules'],
            'conditions.match' => ['nullable', Rule::in(['all', 'any'])],
            'conditions.rules' => ['nullable', 'array', 'max:20'],
            'conditions.rules.*.field' => ['required', Rule::in(GroupMembers::FIELDS)],
            'conditions.rules.*.value' => ['nullable'],
            'conditions.rules.*.min' => ['nullable', 'integer'],
            'conditions.rules.*.max' => ['nullable', 'integer'],
            'conditions.rules.*.not' => ['nullable', 'boolean'],
            'once' => ['nullable', 'boolean'],
            'steps' => ['required', 'array', 'min:1', 'max:20'],
            'steps.*.type' => ['required', Rule::in(array_keys(self::STEP_KEYS))],
            'steps.*.template' => ['nullable', 'string', 'exists:mail_templates,key'],
            'steps.*.to' => ['nullable', Rule::in(['template', 'guardians', 'person'])],
            'steps.*.group_id' => ['nullable', 'integer', Rule::exists('groups', 'id')->where('kind', 'manual')],
            'steps.*.status' => ['nullable', Rule::in(Custom::STATUSES)],
            'steps.*.text' => ['nullable', 'string', 'max:250'],
            'steps.*.name' => ['nullable', 'string', 'alpha_dash', 'max:60'],
            'steps.*.description' => ['nullable', 'string', 'max:250'],
            'steps.*.amount' => ['nullable', 'numeric', 'min:0.01'],
        ]);
        $errors = [];
        $source = (string) $valid['target']['source'];
        if (($source === 'subject') !== in_array($trigger, self::CHANGE_TRIGGERS, true)) {
            $errors['config.target.source'][] = 'Use "subject" for triggers that react to a change, and another source for all other triggers.';
        }
        if ($source === 'event' && ! in_array($trigger, self::EVENT_TRIGGERS, true)) {
            $errors['config.target.source'][] = 'This trigger has no event.';
        }
        $rules = array_values((array) ($valid['conditions']['rules'] ?? []));
        if ($source === 'people' && $rules === []) {
            $errors['config.conditions.rules'][] = 'All people need at least one condition.';
        }
        foreach ($rules as $i => $rule) {
            if (! in_array($rule['field'], ['age', 'events'], true) && ($rule['value'] ?? null) === null) {
                $errors["config.conditions.rules.{$i}.value"][] = 'The value is missing.';
            }
        }
        $steps = [];
        foreach (array_values((array) $valid['steps']) as $i => $step) {
            $type = (string) $step['type'];
            if ($type === 'invoice' && ! ApiContext::allows('finance:write')) {
                $errors["config.steps.{$i}.type"][] = 'A step that creates invoices needs the scope finance:write.';
            }
            foreach (self::STEP_KEYS[$type] as $key) {
                if ($key !== 'to' && ($step[$key] ?? null) === null) {
                    $errors["config.steps.{$i}.{$key}"][] = "The step {$type} needs {$key}.";
                }
            }
            $steps[] = ['type' => $type] + array_filter(array_intersect_key($step, array_flip(self::STEP_KEYS[$type])), fn ($v) => $v !== null);
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $target = ['source' => $source] + match ($source) {
            'event' => array_filter(['statuses' => $valid['target']['statuses'] ?? null, 'roles' => $valid['target']['roles'] ?? null]),
            'group' => ['group_id' => (int) $valid['target']['group_id']],
            default => [],
        };

        return ['target' => $target, 'conditions' => ['match' => $valid['conditions']['match'] ?? 'all', 'rules' => $rules], 'once' => (bool) ($valid['once'] ?? false), 'steps' => $steps];
    }

    /**
     * Validates $values with rules written without the prefix; errors name the full field.
     *
     * @param  array<array-key, mixed>  $values
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private static function prefixed(string $prefix, array $values, array $rules): array
    {
        $full = [];
        foreach ($rules as $key => $rule) {
            $full[$prefix.'.'.$key] = $rule;
        }

        return (array) (Validator::make([$prefix => $values], $full)->validate()[$prefix] ?? []);
    }

    /**
     * @param  array<array-key, mixed>  $keys
     * @param  list<string>  $allowed
     */
    private static function known(array $keys, array $allowed, string $of, string $field = 'automation'): void
    {
        $unknown = array_diff(array_map('strval', $keys), $allowed);
        if ($unknown !== []) {
            throw ValidationException::withMessages([$field => ["Not available for the {$of}: ".implode(', ', $unknown).'.']]);
        }
    }
}
