<?php

namespace App\Support\Automations;

use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Event;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Mail\Notifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Runs automations (AT01–AT10): plans what an action would do (dry run), executes it directly (mode automatic, within
 * max_items) or asks the approvers first (mode confirm, or more items than max_items); manual automations only run
 * from the button. Every run is stored with its plan, decision and result and recorded in the audit log.
 */
class Engine
{
    /** @var array<string, class-string<Action>> */
    public const ACTIONS = [
        'waitlist_offer' => Actions\WaitlistOffer::class,
        'offer_expiry' => Actions\OfferExpiry::class,
        'invoice_run' => Actions\InvoiceRun::class,
        'send_template' => Actions\SendTemplate::class,
        'safety_sheet_reminder' => Actions\SafetySheetReminder::class,
        'start_season' => Actions\StartSeason::class,
        'close_event' => Actions\CloseEvent::class,
        'age_notice' => Actions\AgeNotice::class,
    ];

    /** Actions that work on one event and need it from the trigger or the button. */
    public const NEEDS_EVENT = ['invoice_run', 'send_template', 'start_season', 'close_event'];

    public function __construct(private readonly Approvals $approvals) {}

    public function action(Automation $automation): Action
    {
        $class = self::ACTIONS[$automation->action] ?? throw new RuntimeException('Unknown action '.$automation->action);

        return app($class);
    }

    /**
     * Dry run (AT07): what would happen now, without changing anything.
     *
     * @return list<array{key: string, label: string, details?: string|null}>
     */
    public function preview(Automation $automation, ?Event $event = null): array
    {
        return $this->action($automation)->plan($automation, $event?->loadMissing('roles', 'installments'));
    }

    /**
     * Starts a run. $by: the person who pressed the button (runs directly if they may approve, after they saw the
     * preview); with $forceApproval the approvers always decide (API and MCP without approvals:decide). Returns null if there is nothing to do or the run already exists (dedupe key).
     */
    public function trigger(Automation $automation, string $trigger, ?Event $event = null, ?string $dedupe = null, ?User $by = null, bool $forceApproval = false): ?AutomationRun
    {
        if ($by === null && (! $automation->active || $automation->mode === 'manual')) {
            return null;
        }
        if ($dedupe !== null && $automation->runs()->where('dedupe_key', $dedupe)->exists()) {
            return null;
        }
        // One open request per automation and event: the scheduler waits for the decision; a manual run replaces it.
        $open = $automation->runs()->where('status', 'pending_approval')->where('event_id', $event?->id)->where('expires_at', '>', now())->get();
        if ($open->isNotEmpty()) {
            if ($by === null) {
                return null;
            }
            foreach ($open as $old) {
                $old->forceFill(['status' => 'expired', 'comment' => 'replaced by a manual run'])->save();
                $old->tokens()->whereNull('used_at')->update(['used_at' => now()]);
            }
        }
        $plan = $this->preview($automation, $event);
        if ($plan === []) {
            return null;
        }
        try {
            $run = $automation->runs()->create([
                'dedupe_key' => $dedupe, 'trigger' => $trigger, 'event_id' => $event?->id, 'plan' => $plan, 'status' => 'pending_approval',
                'expires_at' => now()->addDays(max(1, (int) config('kasmin.approval_days', 7))),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null; // started at the same moment by another process
        }
        Audit::record('automation.run_created', $automation, null, ['run' => $run->id, 'trigger' => $trigger, 'items' => count($plan)], $by ? null : 'system');

        // $forceApproval: started via API/MCP without the scope approvals:decide – always a draft for humans (X05).
        if ($by !== null && ! $forceApproval && $this->mayApprove($automation, $by)) {
            $this->decide($run, $by, true);
        } elseif ($by === null && $automation->mode === 'automatic' && count($plan) <= $automation->max_items) {
            $this->execute($run);
        } else {
            $this->approvals->request($run);
            app(Notifier::class)->event('approvals', __('automations.notify.approval_needed', ['name' => $automation->label(), 'count' => count($plan)]), url('/admin/automation-runs/'.$run->id));
        }

        return $run->refresh();
    }

    public function mayApprove(Automation $automation, User $user): bool
    {
        return $user->hasAnyRole($automation->approverRoles());
    }

    /**
     * Approve (optionally without some items, AT06) or deny a pending run.
     *
     * @param  list<string>  $excluded
     */
    public function decide(AutomationRun $run, User $user, bool $approve, array $excluded = [], ?string $comment = null): AutomationRun
    {
        $run->loadMissing('automation');
        if ($run->status !== 'pending_approval') {
            throw new RuntimeException(__('automations.errors.decided'));
        }
        if ($run->expires_at !== null && $run->expires_at->isPast()) {
            throw new RuntimeException(__('automations.errors.expired'));
        }
        if ($run->automation === null || ! $this->mayApprove($run->automation, $user)) {
            throw new RuntimeException(__('automations.errors.not_allowed'));
        }
        $keys = array_column($run->plan, 'key');
        $run->forceFill([
            'status' => $approve ? 'approved' : 'denied', 'decided_by_id' => $user->id, 'decided_at' => now(),
            'excluded' => array_values(array_intersect($excluded, $keys)) ?: null, 'comment' => $comment !== null ? Str::limit($comment, 490) : null,
        ])->save();
        $run->tokens()->whereNull('used_at')->update(['used_at' => now()]);
        Audit::record($approve ? 'automation.approved' : 'automation.denied', $run->automation, null, ['run' => $run->id, 'excluded' => count($run->excluded ?? []), 'comment' => $run->comment], null, $user->id);
        if ($approve) {
            $this->execute($run);
        }

        return $run->refresh();
    }

    /** Executes the selected items of a run; errors of single items do not stop the others (AT08). */
    public function execute(AutomationRun $run): void
    {
        $automation = $run->automation ?? throw new RuntimeException('Run without automation');
        $action = $this->action($automation);
        $event = $run->event?->loadMissing('roles', 'installments');
        $results = [];
        $errors = 0;
        foreach ($run->selectedItems() as $item) {
            try {
                $results[$item['key']] = $action->execute($automation, $item, $event);
            } catch (\Throwable $e) {
                $errors++;
                $results[$item['key']] = 'error: '.Str::limit($e->getMessage(), 200);
                report($e);
            }
        }
        if ($action instanceof Finishes) {
            $action->finish($automation, $event);
        }
        $run->forceFill(['status' => $errors > 0 ? 'failed' : 'done', 'result' => $results, 'executed_at' => now(), 'error' => $errors > 0 ? $errors.' error(s)' : null])->save();
        $automation->forceFill(['last_run_at' => now()])->save();
        Audit::record('automation.executed', $automation, null, ['run' => $run->id, 'items' => count($results), 'errors' => $errors], 'system');
        if ($errors > 0) {
            app(Notifier::class)->event('automation_errors', __('automations.notify.errors', ['name' => $automation->label(), 'count' => $errors]), url('/admin/automation-runs/'.$run->id));
        }
    }

    /** A place became free in an event: start the automations waiting for that (A05). */
    public function placeFreed(Event $event): void
    {
        foreach (Automation::query()->where('active', true)->where('trigger', 'on_place_freed')->get() as $automation) {
            $this->trigger($automation, 'on_place_freed', $event);
        }
    }

    /**
     * Scheduler (hourly): due daily automations, automations before/after events, expired approvals and reminders.
     *
     * @return int runs started
     */
    public function tick(): int
    {
        $started = 0;
        foreach (Automation::query()->where('active', true)->whereIn('trigger', ['daily', 'before_event', 'after_event'])->get() as $automation) {
            foreach ($this->due($automation) as [$event, $dedupe]) {
                $started += $this->trigger($automation, $automation->trigger, $event, $dedupe) !== null ? 1 : 0;
            }
        }
        $this->approvals->housekeeping();

        return $started;
    }

    /** @return list<array{0: Event|null, 1: string}> */
    public function due(Automation $automation): array
    {
        $days = (int) $automation->triggerSetting('days', 0);
        if ($automation->trigger === 'daily') {
            $time = (string) $automation->triggerSetting('time', '08:00');

            return now()->format('H:i') >= $time ? [[null, 'day:'.now()->toDateString()]] : [];
        }
        $events = Event::query()->whereNotIn('status', ['draft', 'cancelled'])
            ->when($automation->triggerSetting('series'), fn ($q, $series) => $q->where('event_series_id', (int) $series));
        $due = [];
        if ($automation->trigger === 'before_event') {
            // From "days before the start" until the start; once per event.
            foreach ($events->where('starts_at', '>', now())->where('starts_at', '<=', now()->addDays($days)->endOfDay())->get() as $event) {
                $due[] = [$event, 'event:'.$event->id];
            }
        } else {
            // From "days after the end" for one week; once per event.
            foreach ($events->where('ends_at', '<=', now()->subDays($days))->where('ends_at', '>', now()->subDays($days + 7))->get() as $event) {
                $due[] = [$event, 'event:'.$event->id];
            }
        }

        return $due;
    }
}
