<?php

namespace App\Support\Reports;

use App\Models\AutomationRun;
use App\Models\BankImport;
use App\Models\Consent;
use App\Models\ContactPoint;
use App\Models\Event;
use App\Models\EventSeries;
use App\Models\Form;
use App\Models\Invoice;
use App\Models\OutgoingMail;
use App\Models\Participation;
use App\Models\Payment;
use App\Models\PublicSubmission;
use App\Support\Automations\Engine;
use App\Support\Automations\Schedule;
use App\Support\Forms\Forms;
use App\Support\Lists\EventLists;
use App\Support\People\Duplicates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Figures for the dashboard (AD01–AD05). Only counts and names – no confidential values.
 */
class DashboardData
{
    /**
     * The next event of every series (or the latest one if none is upcoming), plus upcoming events without series.
     *
     * @return list<array<string, mixed>>
     */
    public function series(bool $withFinance): array
    {
        $blocks = [];
        foreach (EventSeries::query()->orderBy('name')->get() as $series) {
            $event = Event::query()->where('event_series_id', $series->id)->whereNotIn('status', ['cancelled', 'draft'])->where('ends_at', '>=', now())->orderBy('starts_at')->first()
                ?? Event::query()->where('event_series_id', $series->id)->orderByDesc('starts_at')->first();
            if ($event !== null) {
                $blocks[] = $this->eventBlock($event, $series->name, $withFinance);
            }
        }
        foreach (Event::query()->whereNull('event_series_id')->whereNotIn('status', ['cancelled', 'draft'])->where('ends_at', '>=', now())->orderBy('starts_at')->limit(3)->get() as $event) {
            $blocks[] = $this->eventBlock($event, null, $withFinance);
        }

        return $blocks;
    }

    /** @return array<string, mixed> */
    public function eventBlock(Event $event, ?string $series, bool $withFinance): array
    {
        $event->loadMissing('roles');
        $occupying = ['requested', 'registered', 'confirmed', 'offered', 'attended'];
        $roles = $event->roles->map(fn ($r) => [
            'role' => $r->role_key, 'capacity' => $r->capacity, 'occupied' => $r->occupied(), 'bookable_left' => $r->bookableLeft(),
            'reserve_left' => $r->reserveLeft(), 'waitlist' => $r->waitlistLength(),
        ])->values()->all();
        $block = [
            'id' => $event->id, 'series' => $series, 'title' => $event->title, 'starts_at' => $event->starts_at, 'status' => $event->status,
            'days' => (int) now()->startOfDay()->diffInDays($event->starts_at->copy()->startOfDay(), false),
            'registrations' => Participation::query()->where('event_id', $event->id)->whereIn('status', $occupying)->count(),
            'waitlist' => array_sum(array_column($roles, 'waitlist')),
            'open_places' => collect($roles)->contains(fn ($r) => $r['capacity'] !== null) ? (int) collect($roles)->sum(fn ($r) => $r['bookable_left'] ?? 0) : null, // roles without a limit do not count
            'reserve_left' => array_sum(array_column($roles, 'reserve_left')),
            'roles' => $roles,
            'latest' => Participation::query()->where('event_id', $event->id)->whereNotIn('status', ['cancelled', 'declined'])->with('person')
                ->orderByDesc('registered_at')->limit(3)->get()
                ->map(fn (Participation $p) => ['name' => $p->person?->fullName(), 'at' => $p->registered_at, 'role' => $p->role_key, 'status' => $p->status])->all(),
        ];
        if ($withFinance) {
            $invoices = Invoice::query()->where('event_id', $event->id)->where('type', 'invoice')->whereNotIn('status', ['draft', 'cancelled', 'corrected'])->get();
            $block['finance'] = $this->invoiceFigures($invoices);
        }

        return $block;
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
     * @return array<string, array{count: int, cents: int}>
     */
    public function invoiceFigures(Collection $invoices): array
    {
        $paid = $invoices->where('status', 'paid');
        $overdue = $invoices->filter(fn (Invoice $i) => $i->isOverdue());
        $open = $invoices->filter(fn (Invoice $i) => in_array($i->status, ['issued', 'partially_paid'], true) && ! $i->isOverdue());

        return [
            'paid' => ['count' => $paid->count(), 'cents' => (int) $paid->sum('total_cents')],
            'open' => ['count' => $open->count(), 'cents' => (int) $open->sum(fn (Invoice $i) => $i->openCents())],
            'overdue' => ['count' => $overdue->count(), 'cents' => (int) $overdue->sum(fn (Invoice $i) => $i->openCents())],
        ];
    }

    /** @return array<string, int> to-dos (AD02) */
    public function todos(): array
    {
        return [
            'approvals' => AutomationRun::query()->where('status', 'pending_approval')->where('expires_at', '>', now())->count(),
            'requests' => Participation::query()->where('status', 'requested')->count(),
            'new_registrations' => Participation::query()->where('registered_at', '>=', now()->subDays(7))->count(),
            'submissions' => PublicSubmission::query()->where('status', 'pending')->count(),
            'duplicates' => count(app(Duplicates::class)->all()),
            'bad_addresses' => ContactPoint::query()->where('type', 'email')->whereNotNull('bounced_at')->count(),
            'deletion_requests' => Consent::query()->where('kind', 'deletion_request')->where('granted', true)->count(),
            'bounced' => OutgoingMail::query()->whereIn('status', ['bounced', 'failed'])->where('created_at', '>=', now()->subDays(30))->count(),
            'unmatched_payments' => Payment::query()->whereIn('match', ['suggested', 'unmatched'])->count(),
        ];
    }

    /** @return array<string, mixed> (AD03) */
    public function finance(): array
    {
        $invoices = Invoice::query()->where('type', 'invoice')->whereIn('status', ['issued', 'partially_paid'])->get();
        $figures = $this->invoiceFigures($invoices);

        return [
            'open' => $figures['open'], 'overdue' => $figures['overdue'],
            'unmatched' => Payment::query()->whereIn('match', ['suggested', 'unmatched'])->count(),
            'last_import' => BankImport::query()->latest('id')->value('created_at'),
        ];
    }

    /**
     * Safety sheets per upcoming event (AD04): confirmed / missing or expired – counts only.
     *
     * @return list<array{event: string, confirmed: int, missing: int}>
     */
    public function safetySheets(): array
    {
        $form = Form::query()->where('key', 'safety_sheet')->where('active', true)->first();
        if ($form === null) {
            return [];
        }
        $forms = app(Forms::class);
        $lists = app(EventLists::class);
        $result = [];
        foreach (Event::query()->where('starts_at', '>', now())->where('starts_at', '<=', now()->addDays(120))->whereNotIn('status', ['cancelled', 'draft'])->orderBy('starts_at')->limit(4)->get() as $event) {
            $counts = ['confirmed' => 0, 'missing' => 0, 'changed' => 0];
            $form->loadMissing('fields');
            foreach (Participation::query()->where('event_id', $event->id)->whereIn('status', ['registered', 'confirmed', 'offered'])->with('person')->get() as $p) {
                if ($p->person === null) {
                    continue;
                }
                $status = $forms->status($form, $p->person);
                if ($status === 'confirmed') {
                    $counts['confirmed']++;
                    $counts['changed'] += (int) (($lists->changedSince($form, $p->person, $event) ?? []) !== []);
                } elseif ($status !== 'not_applicable') {
                    $counts['missing']++;
                }
            }
            $result[] = ['event' => $event->title] + $counts;
        }

        return $result;
    }

    /**
     * Automation runs due in the next 14 days and failed runs of the last 14 days (AD05).
     *
     * @return array{upcoming: list<array{name: string, at: Carbon, event: string|null}>, failed: int}
     */
    public function automations(): array
    {
        return [
            'upcoming' => app(Schedule::class)->upcoming(),
            'failed' => AutomationRun::query()->where('status', 'failed')->where('created_at', '>=', now()->subDays(14))->count(),
        ];
    }

    /** @return list<string> */
    public static function actions(): array
    {
        return array_keys(Engine::ACTIONS);
    }
}
