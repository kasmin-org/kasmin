<?php

namespace App\Support\Automations;

use App\Models\Event;
use App\Models\EventInstallment;

/**
 * Which instalment an invoice run is for (B8). The automation runs for whatever event it is started with, so it cannot
 * name one instalment; it names its position in the payment plan of the event: "pos:1", "pos:2" … or "pos:last" (in
 * the order of the plan). Up to 0.30 the setting was the key of the instalment ("deposit", "balance"); such a value
 * still works and is offered as the previous setting until it is changed.
 */
final class InstallmentPosition
{
    /** A position (pos:1 to pos:20, pos:last) or the key of older versions. */
    public const PATTERN = '/^(pos:([1-9]|1\d|20|last)|[A-Za-z0-9_-]+)$/';

    /** What the admin interface offers. */
    public const CHOICES = ['pos:1', 'pos:2', 'pos:3', 'pos:last'];

    public const DEFAULT = 'pos:1';

    public static function resolve(?Event $event, mixed $setting): ?EventInstallment
    {
        if ($event === null) {
            return null;
        }
        $setting = is_string($setting) && $setting !== '' ? $setting : self::DEFAULT;
        $plan = $event->installments()->orderBy('id')->get()->values();
        if ($setting === 'pos:last') {
            return $plan->last();
        }
        if (preg_match('/^pos:(\d+)$/', $setting, $match) === 1) {
            return $plan->get((int) $match[1] - 1);
        }

        return $plan->firstWhere('key', $setting); // a key of the versions up to 0.30
    }

    /**
     * Options of the setting in the admin interface; a key stored by an older version is kept as a choice.
     *
     * @return array<string, string>
     */
    public static function options(mixed $current = null): array
    {
        $options = [];
        foreach (self::CHOICES as $choice) {
            $options[$choice] = (string) __('automations.installment_positions.'.$choice);
        }
        if (is_string($current) && $current !== '' && ! isset($options[$current])) {
            $options[$current] = preg_match('/^pos:(\d+)$/', $current, $match) === 1
                ? (string) __('automations.installment_position_n', ['n' => $match[1]])
                : (string) __('automations.installment_previous', ['value' => $current]);
        }

        return $options;
    }
}
