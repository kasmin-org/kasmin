<?php

namespace Tests\Feature\Replay;

use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Event;
use App\Models\EventSeries;
use App\Models\Form;
use App\Models\FormField;
use App\Models\Group;
use App\Models\Household;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Mailing;
use App\Models\MailTemplate;
use App\Models\OutgoingMail;
use App\Models\Participation;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Auth\LoginLinks;
use App\Support\Automations\Engine;
use App\Support\Events\Bus;
use App\Support\Events\Divisions;
use App\Support\Events\Registrations;
use App\Support\Finance\Payments;
use App\Support\Forms\Forms;
use App\Support\Install\Defaults;
use App\Support\Lists\EventLists;
use App\Support\Lists\ListExport;
use App\Support\Settings;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event as Events;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Tests\TestCase;
use Throwable;

/**
 * Replay of two camp years, day by day, as a real organisation lived them: families sign up and register, the season
 * starts for those who come back, deposits and balances are invoiced on the real dates and paid on the real dates,
 * some leave before or after paying, some never pay, the team takes part, the bus is too small, groups and rooms are
 * made, safety sheets are confirmed, the camp is closed, a weekend for adults happens in between.
 *
 * Every day the scheduler commands run and open approvals are given. The test does not stop at the first problem:
 * it collects failed automations, failed mails, errors and texts that were not filled in, and fails at the end with
 * the whole list. With KASMIN_REPLAY_DATA it runs on an anonymised data set from outside the repository; with
 * KASMIN_REPLAY_SMTP_* the mails are really sent (see docs/development/setup.md).
 */
#[TestGroup('replay')]
class TwoYearReplayTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $data;

    private User $admin;

    private EventSeries $series;

    /** @var array<string, Person> dataset id => person */
    private array $people = [];

    /** @var array<string, array<string, mixed>> dataset id => child or adult of the data set */
    private array $source = [];

    /** @var array<string, array<string, mixed>> family id => family of the data set */
    private array $families = [];

    /** @var array<string, bool> family id => has signed up */
    private array $signedUp = [];

    /** @var array<int, Event> year => camp */
    private array $camps = [];

    /** @var array<string, list<callable(): void>> date => things that happen on that day */
    private array $agenda = [];

    /** @var list<string> */
    private array $problems = [];

    /** @var array<int, array<string, bool>> year (negative: weekend) => dataset id => pays in the end, however late */
    private array $pays = [];

    /** @var array<string, int> */
    private array $seen = ['mails' => 0, 'runs' => 0];

    /** @var array<string, float> seconds per kind of work */
    private array $timing = [];

    private string $lastMail = '';

    private int $logOffset = 0;

    private const CAMP = [2025 => ['2025-08-16', '2025-08-23', 39000], 2026 => ['2026-08-22', '2026-08-29', 42000], 2027 => ['2027-08-21', '2027-08-28', 46000]];

    protected function setUp(): void
    {
        parent::setUp();
        $this->data = ReplayData::load();
        Artisan::call('migrate:fresh', ['--force' => true]); // no transaction around the test: the result can be kept and looked at
        $this->withoutMiddleware(AuthenticateSession::class);
        Events::listen(MessageSent::class, function (MessageSent $event): void {
            $this->lastMail = (string) ($event->message->getHtmlBody() ?? $event->message->getTextBody());
            if (str_contains($this->lastMail, 'made-when-sending')) {
                $this->problems[] = now()->toDateString().' a mail was sent with the placeholder instead of the personal link: '.$event->message->getSubject();
            }
        });
        $this->smtp();
        $this->logOffset = is_file($this->logFile()) ? (int) filesize($this->logFile()) : 0;
    }

    public function test_two_years_day_by_day(): void
    {
        $this->travelTo(Carbon::parse('2024-09-10 08:00'));
        $this->organisation();
        $this->plan();

        $end = Carbon::parse(ReplayData::option('KASMIN_REPLAY_UNTIL', '2026-11-25'));
        $started = microtime(true);
        for ($day = Carbon::parse('2024-09-10'); $day->lte($end); $day->addDay()) {
            $date = $day->toDateString();
            $this->travelTo($day->copy()->setTime(8, 0));
            $this->scheduler();
            $this->travelTo($day->copy()->setTime(10, 0));
            foreach ($this->agenda[$date] ?? [] as $n => $step) {
                $this->attempt($date.' step '.$n, $step);
            }
            $this->travelTo($day->copy()->setTime(19, 0));
            $this->scheduler();
            $this->lookForProblems($date);
            if ($day->day === 1 && ReplayData::option('KASMIN_REPLAY_PROGRESS') !== '') {
                fwrite(STDERR, sprintf("%s  %5.0fs  %d mails, %d runs, %d problems\n", $date, microtime(true) - $started, OutgoingMail::query()->count(), AutomationRun::query()->count(), count($this->problems)));
            }
        }

        $this->finalChecks();
        $this->everyAdminPageOpens();

        $this->assertSame([], $this->problems, count($this->problems)." problem(s) in the replay:\n- ".implode("\n- ", $this->problems));
    }

    // ---- set-up -----------------------------------------------------------------------------------------------------

    private function organisation(): void
    {
        app(Defaults::class)->install('de');
        app()->setLocale('de');
        $domain = (string) $this->data['domain'];
        Settings::set('organisation_name', 'Demo-Feriencamp');
        Settings::set('organisation_address', "Beispielgasse 1\n3002 Purkersdorf");
        Settings::set('organisation_email', 'camp@'.$domain);
        Settings::set('organisation_iban', 'AT611904300234573201');
        Settings::set('organisation_bic', 'BKAUATWW');
        Settings::set('organisation_legal_footer', 'Testdaten – kein echter Verein. Beispiel-IBAN, bitte nichts überweisen.');

        $person = Person::query()->create(['first_name' => 'Demo', 'last_name' => 'Admin', 'birth_date' => '1985-05-05', 'locale' => 'de']);
        $person->contactPoints()->create(['type' => 'email', 'value' => 'admin@'.$domain, 'is_primary' => true]);
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', 'administrator')->value('id')]);
        $this->admin = User::query()->firstOrCreate(['person_id' => $person->id], ['name' => 'Demo Admin', 'email' => 'admin@'.$domain, 'password' => 'x']);

        $this->series = EventSeries::query()->create(['name' => 'Sommerlager']);
        Automation::query()->whereIn('key', ['waitlist_offers', 'waitlist_positions', 'deposit_invoices', 'balance_invoices', 'payment_followup', 'safety_sheet_reminders', 'close_event', 'event_information', 'offer_expiry'])->update(['active' => true]);

        foreach ($this->data['families'] as $family) {
            $this->families[$family['id']] = $family;
            foreach ($family['children'] as $child) {
                $this->source[$child['id']] = $child;
            }
        }
        foreach ($this->data['adults'] as $adult) {
            $this->source[$adult['id']] = $adult;
        }
    }

    /** Real mail delivery if a mail server is given; otherwise the mails stay in the application. */
    private function smtp(): void
    {
        $host = ReplayData::option('KASMIN_REPLAY_SMTP_HOST');
        if ($host === '') {
            return;
        }
        config(['mail.default' => 'smtp', 'mail.mailers.smtp' => ['transport' => 'smtp', 'scheme' => 'smtps', 'host' => $host, 'port' => 465,
            'username' => ReplayData::option('KASMIN_REPLAY_SMTP_USER'), 'password' => ReplayData::option('KASMIN_REPLAY_SMTP_PASSWORD'), 'timeout' => 30],
            'mail.from' => ['address' => 'camp@'.$this->data['domain'], 'name' => 'Demo-Feriencamp']]);
    }

    // ---- the plan: what happens on which day ------------------------------------------------------------------------

    private function on(string $date, callable $step): void
    {
        $this->agenda[$date][] = $step;
    }

    private function plan(): void
    {
        $years = array_map('intval', array_keys($this->data['seasons']));
        sort($years);
        foreach ($years as $index => $year) {
            $registrations = $this->data['seasons'][(string) $year]['registrations'];
            $previous = $index > 0 ? array_column($this->data['seasons'][(string) $years[$index - 1]]['registrations'], 'person') : [];
            $issued = array_filter(array_map(fn (array $r) => $r['deposit']['issued_on'] ?? $r['both']['issued_on'] ?? null, $registrations));
            if ($issued === []) {
                continue;
            }
            $start = Carbon::parse(min($issued))->subDays(14)->toDateString();
            $this->on($start, fn () => $this->openSeason($year, $previous !== []));
            $coming = array_column($registrations, 'person');

            // Who was there last year but is not on this year's list says so right after the season has started.
            foreach (array_diff($previous, $coming) as $n => $id) {
                $this->on(Carbon::parse($start)->addDays(2 + $n % 5)->toDateString(), fn () => $this->leave($year, $id, 'not coming back'));
            }
            $byFamily = [];
            foreach ($registrations as $r) {
                $id = (string) $r['person'];
                $first = $r['deposit'] ?? $r['both'];
                // New children are registered by the legal guardian some days before the invoice; who comes back is registered by "start season".
                if (! in_array($id, $previous, true)) {
                    $day = max($start, Carbon::parse($first['issued_on'])->subDays(1 + crc32($id) % 9)->toDateString());
                    $this->on($day, fn () => $this->register($year, $id));
                }
                $byFamily[$this->source[$id]['family'] ?? $id][] = $r;
            }
            foreach (array_unique(array_filter(array_map(fn (array $r) => $r['deposit']['issued_on'] ?? $r['both']['issued_on'] ?? null, $registrations))) as $date) {
                $this->on($date, fn () => $this->invoiceRun($year, 'deposit_invoices'));
            }
            foreach (array_unique(array_filter(array_map(fn (array $r) => $r['balance']['issued_on'] ?? $r['both']['issued_on'] ?? null, $registrations))) as $date) {
                $this->on($date, fn () => $this->reducedPlaces($year, $byFamily));
                $this->on($date, fn () => $this->invoiceRun($year, 'balance_invoices'));
            }
            foreach ($byFamily as $family => $rows) {
                foreach (['deposit', 'balance', 'both'] as $kind) {
                    $invoices = array_values(array_filter(array_map(fn (array $r) => isset($r[$kind]) ? $r[$kind] + ['person' => $r['person']] : null, $rows)));
                    $paid = array_values(array_filter($invoices, fn (array $i) => $i['state'] === 'paid' && $i['paid_on'] !== null));
                    // A child whose invoice was written off left: the legal guardian cancels some days after the invoice. If nobody of
                    // the family pays, nothing is said – reminders and the release of the place have to do their work.
                    foreach (array_filter($invoices, fn (array $i) => $i['state'] === 'writeoff') as $off) {
                        if ($paid !== [] || $kind !== 'deposit') {
                            $this->on(Carbon::parse($off['issued_on'])->addDays(4)->toDateString(), fn () => $this->leave($year, (string) $off['person'], 'left after the '.$kind.' invoice'));
                        }
                    }
                    // Kasmin invoices per family, the data per child: on every day on which the family paid something, it pays what is open then.
                    foreach (array_values(array_unique(array_column($paid, 'paid_on'))) as $n => $date) {
                        $this->on(max($date, Carbon::parse($paid[0]['issued_on'])->addDays(1)->toDateString()), fn () => $this->pay($year, (string) $family, $kind === 'balance' ? 'balance' : 'deposit', $kind === 'both', $n > 0));
                    }
                    foreach ($paid as $row) {
                        $this->pays[$year][(string) $row['person']] = true;
                    }
                }
            }
            [$from] = self::CAMP[$year];
            $camp = Carbon::parse($from);
            if ($camp->lte(Carbon::parse(ReplayData::option('KASMIN_REPLAY_UNTIL', '2026-11-25')))) {
                $this->on($camp->copy()->subDays(80)->toDateString(), fn () => $this->team($year));
                foreach ($coming as $n => $id) {
                    // Two thirds confirm the safety sheet by themselves, the rest after reminders, two not at all.
                    if ($n % 3 !== 0) {
                        $this->on($camp->copy()->subDays(70 - $n % 20)->toDateString(), fn () => $this->safetySheet($year, (string) $id));
                    } elseif ($n > 3) {
                        $this->on($camp->copy()->subDays(20 - $n % 8)->toDateString(), fn () => $this->safetySheet($year, (string) $id));
                    }
                }
                $this->on($camp->copy()->subDays(30)->toDateString(), fn () => $this->bus($year));
                $this->on($camp->copy()->subDays(14)->toDateString(), fn () => $this->makeGroups($year));
                $this->on($camp->copy()->subDays(7)->toDateString(), fn () => $this->lists($year));
                $this->on(Carbon::parse(self::CAMP[$year][1])->addDays(5)->toDateString(), fn () => $this->confirmations($year));
            }
            $weekend = $this->data['seasons'][(string) $year]['weekend'];
            if ($weekend !== []) {
                $this->planWeekend($year, $weekend);
            }
        }
        ksort($this->agenda);
    }

    // ---- what people do ---------------------------------------------------------------------------------------------

    private function openSeason(int $year, bool $returning): void
    {
        [$from, $to, $price] = self::CAMP[$year];
        $event = Event::query()->create([
            'title' => 'Sommerlager '.$year, 'event_series_id' => $this->series->id, 'starts_at' => $from.' 09:00', 'ends_at' => $to.' 16:00', 'status' => 'open',
            'location' => 'Mistelbach', 'sibling_mode' => 'staggered', 'sibling_amount_cents' => 4000, 'sibling_cap_position' => 3,
        ]);
        $event->roles()->create(['role_key' => 'child', 'capacity' => 60, 'min_age' => 5, 'max_age' => 17, 'price_cents' => $price]);
        $event->roles()->create(['role_key' => 'staff', 'capacity' => null, 'min_age' => 16, 'registered_by' => 'admin', 'counts_as_sibling' => false]);
        $event->installments()->create(['key' => 'deposit', 'label' => ['en' => 'Deposit', 'de' => 'Anzahlung'], 'kind' => 'fixed', 'amount_cents' => 8000, 'due_days' => 14, 'discounts_apply' => false, 'refundable' => false, 'sort' => 1]);
        $event->installments()->create(['key' => 'balance', 'label' => ['en' => 'Balance', 'de' => 'Restbetrag'], 'kind' => 'rest', 'due_date' => Carbon::parse($from)->subDays(40)->toDateString(), 'discounts_apply' => true, 'refundable' => true, 'sort' => 2]);
        $this->camps[$year] = $event;
        if ($returning) {
            $run = app(Engine::class)->trigger(Automation::query()->where('key', 'start_season')->firstOrFail(), 'manual', $event, null, $this->admin);
            $this->check($run !== null && $run->status === 'done', "start season {$year}: ".json_encode($run?->result));
        }
    }

    private function register(int $year, string $id): void
    {
        $source = $this->source[$id];
        $family = $this->families[$source['family'] ?? ''] ?? null;
        if ($family === null) {
            return; // adults take part as team
        }
        $this->guardianLogsIn($family);
        $person = $this->people[$id] ?? null;
        if ($person === null) {
            $this->post('/portal/children', ['first_name' => $source['first_name'], 'last_name' => $source['last_name'], 'birth_date' => $source['birth_date'], 'gender' => in_array($source['gender'], ['female', 'male', 'diverse'], true) ? $source['gender'] : 'female'])->assertSessionHasNoErrors()->assertRedirect();
            $person = $this->people[$id] = Person::query()->where('first_name', $source['first_name'])->where('last_name', $source['last_name'])->whereDate('birth_date', $source['birth_date'])->latest('id')->firstOrFail();
        }
        $event = $this->camps[$year];
        if (! Participation::query()->where('event_id', $event->id)->where('person_id', $person->id)->whereNotIn('status', ['cancelled', 'declined'])->exists()) {
            if ($event->refresh()->isOpenForRegistration()) {
                $this->get(route('portal.child.register.form', [$person->public_id, $event->public_id, 'child']))->assertOk();
                $this->post('/portal/children/'.$person->public_id.'/register', ['event' => $event->public_id, 'role' => 'child'])->assertSessionHasNoErrors()->assertRedirect();
            } else {
                // Somebody joins after the registration has closed (it happened: during the camp): the team registers the child.
                app(Registrations::class)->register($event, $person, 'child', null, force: true);
            }
        }
        $this->get('/portal')->assertOk()->assertSee($source['first_name']);
        $this->post('/logout');
        $this->check(Participation::query()->where('event_id', $event->id)->where('person_id', $person->id)->whereIn('status', ['registered', 'waitlist'])->exists(), "registration of {$id} for {$year} is missing");
    }

    /**
     * The first time a family signs up on the public page; later the legal guardian uses a login link.
     *
     * @param  array<string, mixed>  $family
     */
    private function guardianLogsIn(array $family): void
    {
        $guardian = $family['guardian'];
        if (! isset($this->signedUp[$family['id']])) {
            $this->lastMail = '';
            $this->openSignUp(); // signing up is a public form, switched off by default (B16)
            $this->post('/register', ['first_name' => $guardian['first_name'], 'last_name' => $guardian['last_name'], 'email' => $guardian['email'], 'locale' => 'de', 'privacy' => '1'])->assertSessionHasNoErrors()->assertRedirect('/login/code');
            preg_match('~/register/confirm/([A-Za-z0-9]+)~', $this->lastMail, $m);
            $this->check(isset($m[1]), 'no confirmation link in the sign-up mail for '.$family['id']);
            $login = (string) $this->post('/register/confirm/'.($m[1] ?? 'x'))->assertRedirect()->headers->get('Location');
            $this->post($login)->assertRedirect(route('portal'));
            $this->signedUp[$family['id']] = true;
            $household = Household::query()->latest('id')->first();
            $household?->update(['street' => $family['street'], 'postal_code' => $family['postal_code'], 'city' => $family['city'], 'country' => $family['country']]);

            return;
        }
        $user = User::query()->where('email', $guardian['email'])->firstOrFail();
        $this->post(route('login.link.consume', app(LoginLinks::class)->issue($user)['token']))->assertRedirect(route('portal'));
    }

    private function leave(int $year, string $id, string $why): void
    {
        $person = $this->people[$id] ?? null;
        $participation = $person === null ? null : Participation::query()->where('event_id', $this->camps[$year]->id)->where('person_id', $person->id)->whereNotIn('status', ['cancelled', 'declined'])->first();
        if ($participation === null) {
            return; // e.g. the place was released already
        }
        $this->guardianLogsIn($this->families[$this->source[$id]['family']]);
        $this->post(route('portal.child.cancel', [$person->public_id, $participation->id]))->assertSessionHasNoErrors()->assertRedirect();
        $this->post('/logout');
        $this->check($participation->refresh()->status === 'cancelled', "{$id} ({$why}) is still ".$participation->status);
    }

    /**
     * Before the balance is invoiced the team enters the places that cost less than the price list says (junior
     * counsellors, agreements with a family): whatever the data show and the sibling discount does not explain.
     *
     * @param  array<string, list<array<string, mixed>>>  $byFamily
     */
    private function reducedPlaces(int $year, array $byFamily): void
    {
        $price = self::CAMP[$year][2];
        foreach ($byFamily as $rows) {
            $totals = [];
            foreach ($rows as $r) {
                $person = $this->people[$r['person']] ?? null;
                $total = isset($r['both']) ? 100 * (int) $r['both']['amount'] : (isset($r['balance'], $r['deposit']) && $r['balance']['state'] !== 'writeoff' ? 100 * ((int) $r['deposit']['amount'] + (int) $r['balance']['amount']) : null);
                if ($person !== null && $total !== null) {
                    $totals[$person->id] = $total;
                }
            }
            arsort($totals);
            // Children are taken out one by one until the sibling discount explains the rest (reduced places do not count as siblings).
            for ($round = 0; $round < 5; $round++) {
                $position = 0;
                $odd = null;
                foreach ($totals as $personId => $total) {
                    if ($total !== $price - 4000 * min($position, 2)) {
                        $odd = $personId;
                        break;
                    }
                    $position++;
                }
                if ($odd === null) {
                    break;
                }
                Participation::query()->where('event_id', $this->camps[$year]->id)->where('person_id', $odd)->whereNotIn('status', ['cancelled', 'declined'])
                    ->get()->each(fn (Participation $p) => $p->forceFill(['price_cents' => $totals[$odd], 'price_reason' => 'wie vereinbart'])->save());
                unset($totals[$odd]);
            }
        }
    }

    private function invoiceRun(int $year, string $automation): void
    {
        $run = app(Engine::class)->trigger(Automation::query()->where('key', $automation)->firstOrFail(), 'manual', $this->camps[$year], null, $this->admin);
        $this->check($run === null || in_array($run->status, ['done', 'pending_approval'], true), "{$automation} {$year}: ".json_encode($run?->result));
        $this->approveAll();
    }

    /** The family pays what is open for this instalment by bank transfer; the statement is imported. */
    private function pay(int $year, string $family, string $installment, bool $everything = false, bool $maybeDone = false): void
    {
        $ids = array_map(fn (array $c) => $this->people[$c['id']]->id ?? 0, $this->families[$family]['children'] ?? []);
        $invoices = Invoice::query()->where('event_id', $this->camps[$year]->id)->where('type', 'invoice')->whereIn('status', ['issued', 'partially_paid'])
            ->when(! $everything, fn ($q) => $q->where('installment_key', $installment))->whereHas('lines', fn ($q) => $q->whereIn('person_id', $ids))->get();
        if ($invoices->isEmpty()) {
            if ($maybeDone) {
                return; // one family invoice was paid with the first transfer
            }
            $this->problems[] = now()->toDateString()." {$family} wants to pay the {$installment} of {$year}, but there is no open invoice";

            return;
        }
        $csv = (string) tempnam(sys_get_temp_dir(), 'bank');
        $content = "Date;Amount;Text;Name\n";
        foreach ($invoices as $n => $invoice) {
            // People write the reference, the number or nothing useful at all.
            $text = match (($invoice->id + $n) % 4) {
                0 => 'Sommerlager '.$invoice->reference, 1 => (string) $invoice->number, 2 => 'Rechnung '.$invoice->number.' Danke', default => (string) $invoice->reference,
            };
            $content .= now()->format('d.m.Y').';'.number_format($invoice->openCents() / 100, 2, ',', '').';'.$text.';'.$invoice->payer_name."\n";
        }
        file_put_contents($csv, $content);
        try {
            app(Payments::class)->import($csv, 'bank.csv', ['date' => 0, 'amount' => 1, 'reference' => 2, 'name' => 3]);
        } finally {
            unlink($csv);
        }
        foreach ($invoices as $invoice) {
            $this->check($invoice->refresh()->status === 'paid', "invoice {$invoice->number} of {$family} is {$invoice->status} after the payment");
        }
    }

    private function team(int $year): void
    {
        foreach ($this->data['adults'] as $adult) {
            if (! $adult['team']) {
                continue;
            }
            $person = $this->adult($adult);
            app(Registrations::class)->register($this->camps[$year], $person, 'staff', null, force: true);
        }
    }

    /** @param  array<string, mixed>  $adult */
    private function adult(array $adult): Person
    {
        if (isset($this->people[$adult['id']])) {
            return $this->people[$adult['id']];
        }
        $person = Person::query()->create(['first_name' => $adult['first_name'], 'last_name' => $adult['last_name'], 'birth_date' => $adult['birth_date'], 'gender' => $adult['gender'] ?: null, 'locale' => 'de']);
        $person->contactPoints()->create(['type' => 'email', 'value' => $adult['email'], 'is_primary' => true]);
        $person->contactPoints()->create(['type' => 'phone', 'value' => $adult['phone'], 'is_primary' => true]);
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', 'counsellor')->value('id')]);
        User::query()->firstOrCreate(['person_id' => $person->id], ['name' => $person->fullName(), 'email' => $adult['email'], 'password' => 'x']);

        return $this->people[$adult['id']] = $person;
    }

    private function safetySheet(int $year, string $id): void
    {
        $person = $this->people[$id] ?? null;
        if ($person === null || ! Participation::query()->where('event_id', $this->camps[$year]->id)->where('person_id', $person->id)->whereIn('status', ['registered', 'confirmed'])->exists()) {
            return;
        }
        $form = Form::query()->where('key', 'safety_sheet')->firstOrFail();
        if (in_array(app(Forms::class)->status($form, $person), ['not_applicable', 'confirmed'], true)) {
            return; // above the age limit of the sheet, or confirmed already
        }
        $this->guardianLogsIn($this->families[$this->source[$id]['family']]);
        $url = '/portal/children/'.$person->public_id.'/forms/safety_sheet';
        $this->get($url)->assertOk();
        $status = app(Forms::class)->status($form, $person);
        if ($status === 'confirmed') {
            $this->post('/logout');

            return;
        }
        $n = crc32($id);
        $this->post($url, ['values' => [
            'diet' => $n % 5 === 0 ? 'vegetarian' : 'none', 'intolerances' => $n % 7 === 0 ? 'Laktose' : FormField::NONE, 'allergies' => $n % 6 === 0 ? 'Haselnüsse' : FormField::NONE,
            'medical_needs' => FormField::NONE, 'tetanus' => (string) (2019 + $n % 6), 'tbe' => $n % 4 === 0 ? 'unknown' : (string) (2022 + $n % 4), 'swimmer' => $n % 9 === 0 ? 'no' : 'yes',
            'photos_internal' => 'yes', 'photos_public' => $n % 3 === 0 ? 'no' : 'yes',
        ]])->assertSessionHasNoErrors()->assertRedirect();
        $this->post('/logout');
        $this->check(app(Forms::class)->status($form, $person) === 'confirmed', "safety sheet of {$id} for {$year} is ".app(Forms::class)->status($form, $person));
    }

    /** The bus is two seats too small: adults go by car until it fits; one child is brought by its legal guardians. */
    private function bus(int $year): void
    {
        $event = $this->camps[$year];
        $riders = Bus::status($event)['riders'];
        $event->update(['bus_booked' => true, 'bus_seats' => max(1, $riders - 3)]);
        $this->check(Bus::status($event->refresh())['over'] === 3, "bus {$year}: expected 3 too many, got ".json_encode(Bus::status($event)));
        $child = Participation::query()->where('event_id', $event->id)->where('role_key', 'child')->whereIn('status', Bus::STATUSES)->first();
        if ($child !== null) {
            Bus::set($child, 'car');
        }
        foreach (Participation::query()->where('event_id', $event->id)->where('role_key', 'staff')->whereIn('status', Bus::STATUSES)->with('person')->get() as $adult) {
            if (Bus::status($event)['over'] === 0) {
                break;
            }
            Bus::set($adult, 'car');
        }
        $status = Bus::status($event);
        $this->check($status['over'] === 0 && $status['cars'] >= 2, "bus {$year} still overbooked: ".json_encode($status));
        $this->check(count(app(EventLists::class)->build($event, 'bus')->rows) === $status['riders'], "bus list {$year} does not match the riders");
    }

    private function makeGroups(int $year): void
    {
        $event = $this->camps[$year];
        $service = app(Divisions::class);
        $children = Participation::query()->where('event_id', $event->id)->where('role_key', 'child')->whereIn('status', Divisions::STATUSES)->count();
        $groups = $service->create($event, 'Gruppen', 'groups', 5, null, ['roles' => ['child']]);
        $this->check($service->propose($groups) === $children, "groups {$year}: not everybody was placed");
        $sizes = $groups->units()->get()->map(fn ($u) => $u->participations()->count())->all();
        $this->check($sizes !== [] && max($sizes) - min($sizes) <= 1, "groups {$year}: sizes ".implode('/', $sizes));
        foreach ($service->warnings($groups) as $warning) {
            $this->problems[] = "groups {$year}: {$warning}";
        }
        $rooms = $service->create($event, 'Zimmer', 'rooms', (int) ceil($children / 5) + 3, 6);
        $service->propose($rooms);
        foreach ($service->warnings($rooms) as $warning) {
            $this->problems[] = "rooms {$year}: {$warning}";
        }
    }

    /** Every list of the event in every format, as the safety officer would open them. */
    private function lists(int $year): void
    {
        $this->actingAs($this->admin)->withSession(['two_factor_passed' => true]);
        $lists = app(EventLists::class);
        foreach (array_keys(EventLists::LISTS) as $key) {
            $list = $lists->build($this->camps[$year], $key);
            foreach ($list->rows as $row) {
                $this->check(count($row) === count($list->headers), "list {$key} {$year}: a row has ".count($row).' cells for '.count($list->headers).' headers');
            }
            foreach (ListExport::FORMATS as $format) {
                $this->check(app(ListExport::class)->render($list, $format, 'Sommerlager') !== '', "list {$key} as {$format} is empty");
            }
        }
        $this->get(route('admin.lists.export', [$this->camps[$year], 'participants', 'pdf']))->assertOk();
        $this->post('/logout');
        auth()->forgetUser();
    }

    private function confirmations(int $year): void
    {
        $event = $this->camps[$year]->refresh();
        $this->check($event->status === 'finished', "camp {$year} is {$event->status} after its end");
        $attended = Participation::query()->where('event_id', $event->id)->where('role_key', 'child')->where('status', 'attended')->with('person')->limit(3)->get();
        $this->check($attended->isNotEmpty(), "camp {$year}: nobody is marked as attended");
        foreach ($attended as $p) {
            $id = (string) array_search($p->person_id, array_map(fn (Person $x) => $x->id, $this->people), true);
            $this->guardianLogsIn($this->families[$this->source[$id]['family']]);
            $pdf = $this->get(route('portal.child.confirmation', [$p->person?->public_id, $p->id]))->assertOk();
            $this->check(str_starts_with((string) $pdf->getContent(), '%PDF'), "confirmation of participation {$year} is not a PDF");
            $this->post('/logout');
        }
    }

    /** @param  list<array<string, mixed>>  $weekend */
    private function planWeekend(int $year, array $weekend): void
    {
        $issued = (string) collect($weekend)->min('issued_on');
        $open = Carbon::parse($issued)->subDays(12)->toDateString();
        $this->on($open, function () use ($year, $issued): void {
            $event = Event::query()->create(['title' => 'Wochenende '.$year, 'starts_at' => Carbon::parse($issued)->addDays(60)->toDateString().' 17:00', 'ends_at' => Carbon::parse($issued)->addDays(62)->toDateString().' 14:00', 'status' => 'open']);
            $event->roles()->create(['role_key' => 'guest', 'capacity' => 40, 'min_age' => 15, 'registered_by' => 'self', 'counts_as_sibling' => false, 'price_cents' => 9500]);
            $event->installments()->create(['key' => 'deposit', 'label' => ['en' => 'Fee', 'de' => 'Beitrag'], 'kind' => 'rest', 'due_days' => 14, 'discounts_apply' => false, 'refundable' => true, 'sort' => 1]);
            $this->camps[-$year] = $event;
        });
        $seen = [];
        foreach ($weekend as $n => $row) {
            $adult = $this->source[$row['person']] ?? null;
            if ($adult === null || ($adult['kind'] ?? '') !== 'adult' || isset($seen[$row['person']])) {
                continue; // only adults with an own login register themselves, and once
            }
            $seen[$row['person']] = true;
            if ($row['state'] === 'paid') {
                $this->pays[-$year][(string) $row['person']] = true;
            }
            $this->on(Carbon::parse($open)->addDays(1 + $n % 8)->toDateString(), function () use ($year, $adult): void {
                $person = $this->adult($adult);
                $user = User::query()->where('email', $adult['email'])->firstOrFail();
                $this->post(route('login.link.consume', app(LoginLinks::class)->issue($user)['token']))->assertRedirect();
                $this->post('/portal/children/'.$person->public_id.'/register', ['event' => $this->camps[-$year]->public_id, 'role' => 'guest'])->assertSessionHasNoErrors()->assertRedirect();
                $this->post('/logout');
            });
            if ($row['state'] === 'paid' && $row['paid_on'] !== null) {
                $this->on(max($row['paid_on'], Carbon::parse($row['issued_on'])->addDays(2)->toDateString()), function () use ($year, $adult): void {
                    $invoice = Invoice::query()->where('event_id', $this->camps[-$year]->id)->where('payer_id', $this->people[$adult['id']]->id)->whereIn('status', ['issued', 'partially_paid'])->first();
                    if ($invoice === null) {
                        $p = Participation::query()->where('event_id', $this->camps[-$year]->id)->where('person_id', $this->people[$adult['id']]->id)->first();
                        $this->problems[] = now()->toDateString().' weekend: no open invoice for '.$adult['id'].' (registration: '.($p->status ?? 'none').', invoices: '
                            .Invoice::query()->where('event_id', $this->camps[-$year]->id)->where('payer_id', $this->people[$adult['id']]->id)->pluck('status')->implode(',').')';

                        return;
                    }
                    app(Payments::class)->record($invoice, $invoice->openCents(), now(), 'bank');
                    $this->check($invoice->refresh()->status === 'paid', 'weekend invoice '.$invoice->number.' is '.$invoice->status);
                });
            }
        }
        $this->on($issued, fn () => $this->invoiceRun(-$year, 'deposit_invoices'));
    }

    // ---- the system -------------------------------------------------------------------------------------------------

    /** What the cron job does, and the board gives every approval that is asked for. */
    private function scheduler(): void
    {
        foreach (['kasmin:events:status', 'kasmin:automations:run', 'kasmin:groups:update'] as $command) {
            $this->attempt($command, fn () => Artisan::call($command));
        }
        $this->approveAll();
        if (now()->hour === 8) {
            $this->attempt('digest', fn () => Artisan::call('kasmin:notifications:digest'));
            $this->attempt('retention', fn () => Artisan::call('kasmin:privacy:retention'));
        }
        $this->attempt('kasmin:mail:send', function (): void {
            for ($i = 0; $i < 20 && OutgoingMail::query()->where('status', 'queued')->where(fn ($q) => $q->whereNull('send_after')->orWhere('send_after', '<=', now()))->exists(); $i++) {
                Artisan::call('kasmin:mail:send', ['--limit' => 200]);
            }
        });
    }

    private function approveAll(): void
    {
        foreach (AutomationRun::query()->where('status', 'pending_approval')->with('automation')->get() as $run) {
            $this->attempt('approval of run '.$run->id, fn () => app(Engine::class)->decide($run, $this->admin, true, $run->automation?->key === 'payment_followup' ? $this->latePayers($run) : []));
        }
    }

    /**
     * The treasurer knows the families: places of people who pay late, but pay, are not released (in the data their
     * invoices are paid in the end). Returns the items to leave out of the approval.
     *
     * @return list<string>
     */
    private function latePayers(AutomationRun $run): array
    {
        $keep = [];
        $ids = array_flip(array_map(fn (Person $p) => $p->id, $this->people));
        foreach ($run->plan ?? [] as $item) {
            $line = InvoiceLine::query()->with('participation')->find((int) substr((string) $item['key'], 5));
            $participation = $line?->participation;
            if ($participation === null) {
                continue;
            }
            $year = array_search($participation->event_id, array_map(fn (Event $e) => $e->id, $this->camps), true);
            $dataset = $ids[$participation->person_id] ?? null;
            if ($year !== false && $dataset !== null && isset($this->pays[$year][$dataset])) {
                $keep[] = (string) $item['key'];
            }
        }

        return $keep;
    }

    private function attempt(string $what, callable $step): void
    {
        $t = microtime(true);
        try {
            $step();
            $key = preg_replace('/\d+/', 'N', str_contains($what, ' step ') ? 'agenda' : $what);
            $this->timing[$key] = ($this->timing[$key] ?? 0) + microtime(true) - $t;
        } catch (Throwable $e) {
            $frame = collect($e->getTrace())->first(fn (array $f) => str_contains((string) ($f['file'] ?? ''), 'TwoYearReplayTest'));
            $this->problems[] = now()->toDateString()." {$what}: ".class_basename($e).': '.mb_substr($e->getMessage(), 0, 300).' ('.basename($e->getFile()).':'.$e->getLine().', replay line '.($frame['line'] ?? '?').')';
            try {
                $this->post('/logout');
            } catch (Throwable) {
            }
            auth()->forgetUser();
        }
    }

    private function check(bool $ok, string $problem): void
    {
        if (! $ok) {
            $this->problems[] = now()->toDateString().' '.$problem;
        }
    }

    private function logFile(): string
    {
        return storage_path('logs/laravel.log');
    }

    private function lookForProblems(string $date): void
    {
        foreach (AutomationRun::query()->where('id', '>', $this->seen['runs'])->orderBy('id')->get() as $run) {
            $this->seen['runs'] = $run->id;
            if ($run->status === 'failed') {
                $this->problems[] = "{$date} automation run {$run->id} ({$run->automation?->key}) failed: ".mb_substr((string) json_encode($run->result), 0, 300);
            }
        }
        foreach (OutgoingMail::query()->where('id', '>', $this->seen['mails'])->orderBy('id')->get() as $mail) {
            $this->seen['mails'] = $mail->id;
            $text = $mail->subject.' '.$mail->html;
            if (preg_match('/\{\{|\}\}|\{%/', $text, $m) === 1) {
                $this->problems[] = "{$date} mail {$mail->id} \"{$mail->subject}\" has text that was not filled in: {$m[0]}";
            }
            if (preg_match('/\b(admin|portal|events|finance|lists|automations|mail|forms)\.[a-z_]+\.[a-z_.]+\b/', strip_tags($text), $m) === 1 && ! str_contains($m[0], '@')) {
                $this->problems[] = "{$date} mail {$mail->id} \"{$mail->subject}\" shows a missing translation: {$m[0]}";
            }
            if (trim(strip_tags((string) $mail->html)) === '' || trim((string) $mail->subject) === '') {
                $this->problems[] = "{$date} mail {$mail->id} is empty";
            }
        }
        foreach (OutgoingMail::query()->whereIn('status', ['failed', 'bounced'])->where('updated_at', '>=', Carbon::parse($date))->get() as $mail) {
            $this->problems[] = "{$date} mail {$mail->id} \"{$mail->subject}\" to {$mail->to_address} is {$mail->status}: ".mb_substr((string) $mail->getAttribute('error'), 0, 200);
        }
        if (is_file($this->logFile()) && filesize($this->logFile()) > $this->logOffset) {
            $new = (string) file_get_contents($this->logFile(), false, null, $this->logOffset);
            $this->logOffset = (int) filesize($this->logFile());
            preg_match_all('/^\[[^\]]+\] \w+\.(ERROR|CRITICAL|EMERGENCY): (.{0,300})/m', $new, $matches);
            foreach (array_unique($matches[2]) as $line) {
                $this->problems[] = "{$date} error in the log: {$line}";
            }
        }
    }

    // ---- the end ----------------------------------------------------------------------------------------------------

    private function finalChecks(): void
    {
        foreach ([2025, 2026] as $year) {
            $season = $this->data['seasons'][(string) $year] ?? null;
            if ($season === null || ! isset($this->camps[$year])) {
                continue;
            }
            $event = $this->camps[$year];
            $expected = count(array_filter($season['registrations'], fn (array $r) => ($r['balance']['state'] ?? $r['both']['state'] ?? '') === 'paid'));
            $attended = Participation::query()->where('event_id', $event->id)->where('role_key', 'child')->where('status', 'attended')->count();
            $this->check($attended === $expected, "camp {$year}: {$attended} children attended, the data say {$expected}");
            $open = Invoice::query()->where('event_id', $event->id)->where('type', 'invoice')->whereIn('status', ['issued', 'partially_paid', 'draft'])->count();
            foreach (Invoice::query()->where('event_id', $event->id)->where('type', 'invoice')->whereIn('status', ['issued', 'partially_paid', 'draft'])->with('lines.participation')->get() as $invoice) {
                $this->problems[] = "camp {$year}: invoice ".($invoice->number ?? 'draft #'.$invoice->id)." ({$invoice->installment_key}, {$invoice->status}, ".($invoice->total_cents / 100).', paid '.($invoice->paid_cents / 100).') is still open after the camp; registrations: '
                    .$invoice->lines->map(fn ($l) => ($l->participation->status ?? '–').' '.($l->amount_cents / 100))->implode(', ');
            }
            $paid = (int) Invoice::query()->where('event_id', $event->id)->where('type', 'invoice')->where('status', 'paid')->sum('total_cents');
            $data = 100 * array_sum(array_map(fn (array $r) => array_sum(array_map(fn (string $k) => ($r[$k]['state'] ?? '') === 'paid' ? (int) $r[$k]['amount'] : 0, ['deposit', 'balance', 'both'])), $season['registrations']));
            if (ReplayData::option('KASMIN_REPLAY_DATA') !== '') { // the fictitious data have flat amounts
                $this->check($paid === $data, "camp {$year}: paid invoices sum up to ".($paid / 100).', the data say '.($data / 100));
                $this->compareFamilies($year, $event, $season['registrations']);
            }
            foreach ($season['registrations'] as $r) {
                $person = $this->people[$r['person']] ?? null;
                $should = ($r['balance']['state'] ?? $r['both']['state'] ?? '') === 'paid';
                $status = $person === null ? 'no person' : (string) Participation::query()->where('event_id', $event->id)->where('person_id', $person->id)->orderByDesc('id')->value('status');
                $this->check(($status === 'attended') === $should, "camp {$year}: {$r['person']} is \"{$status}\", the data say ".($should ? 'attended' : 'left').' ('.json_encode(array_map(fn ($i) => is_array($i) ? $i['state'].' '.$i['issued_on'].' paid '.$i['paid_on'] : $i, $r)).')');
            }
        }
        if (isset($this->camps[2027], $this->data['seasons']['2027'])) {
            $season = $this->data['seasons']['2027']['registrations'];
            $registered = Participation::query()->where('event_id', $this->camps[2027]->id)->where('role_key', 'child')->whereIn('status', ['registered', 'confirmed'])->count();
            $this->check($registered === count($season), "camp 2027: {$registered} children are registered, the data say ".count($season));
        }
        $stuck = OutgoingMail::query()->whereIn('status', ['queued', 'sending', 'held'])->where('created_at', '<', now()->subDays(2))->count();
        $this->check($stuck === 0, "{$stuck} mails are older than two days and not sent");
        $slow = [];
        foreach (Automation::query()->where('active', true)->get() as $automation) {
            foreach ($automation->trigger === 'daily' ? [null] : array_values($this->camps) as $event) {
                $t = microtime(true);
                try {
                    app(Engine::class)->preview($automation, $event);
                } catch (Throwable) {
                }
                $slow[$automation->key] = ($slow[$automation->key] ?? 0) + microtime(true) - $t;
            }
        }
        arsort($slow);
        fwrite(STDERR, "\nPlan of each automation at the end (ms): ".collect($slow)->map(fn (float $v, string $k) => $k.' '.round($v * 1000))->implode(', ')."\n");
        arsort($this->timing);
        fwrite(STDERR, "\nSeconds: ".collect($this->timing)->map(fn (float $v, string $k) => $k.' '.round($v))->take(8)->implode(', ')."\n");
        fwrite(STDERR, sprintf("\nReplay: %d people, %d registrations, %d invoices, %d mails (%s), %d automation runs.\n", Person::query()->count(), Participation::query()->count(), Invoice::query()->count(),
            OutgoingMail::query()->count(), collect(OutgoingMail::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'))->map(fn ($n, $s) => "{$s} {$n}")->implode(', '), AutomationRun::query()->count()));
    }

    /**
     * Where the money differs from the data: family by family.
     *
     * @param  list<array<string, mixed>>  $registrations
     */
    private function compareFamilies(int $year, Event $event, array $registrations): void
    {
        $expected = [];
        foreach ($registrations as $r) {
            $family = (string) ($this->source[$r['person']]['family'] ?? $r['person']);
            foreach (['deposit', 'balance', 'both'] as $kind) {
                $expected[$family] = ($expected[$family] ?? 0) + (($r[$kind]['state'] ?? '') === 'paid' ? 100 * (int) $r[$kind]['amount'] : 0);
            }
        }
        foreach ($expected as $family => $cents) {
            $ids = array_values(array_filter(array_map(fn (array $c) => $this->people[$c['id']]->id ?? null, $this->families[$family]['children'] ?? [])));
            $invoices = Invoice::query()->where('event_id', $event->id)->where('type', 'invoice')->whereHas('lines', fn ($q) => $q->whereIn('person_id', $ids))->with('lines')->get();
            $paid = (int) $invoices->where('status', 'paid')->sum('total_cents');
            if ($paid !== $cents) {
                $this->problems[] = "camp {$year}: family {$family} paid ".($paid / 100).' in Kasmin, '.($cents / 100).' in the data; invoices: '
                    .$invoices->map(fn (Invoice $i) => $i->installment_key.' '.$i->status.' '.($i->total_cents / 100).' ['.$i->lines->map(fn ($l) => ($l->amount_cents / 100).($l->discount_cents ? ' (−'.($l->discount_cents / 100).')' : ''))->implode(' + ').']')->implode('; ');
            }
        }
    }

    /** Every page of the admin interface opens with the data of two years, also the pages of single records. */
    private function everyAdminPageOpens(): void
    {
        $this->actingAs($this->admin)->withSession(['two_factor_passed' => true]);
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name = (string) $route->getName();
            if (! str_starts_with($name, 'filament.admin.') || ! in_array('GET', $route->methods(), true) || str_contains($name, '.auth.')) {
                continue;
            }
            $uri = $route->uri();
            if (str_contains($uri, '{')) {
                $model = $this->recordFor($name);
                if ($model === null) {
                    continue;
                }
                $uri = (string) preg_replace('/\{[^}]+\}/', (string) $model, $uri);
            }
            $this->attempt('page /'.$uri, function () use ($uri): void {
                $status = $this->get('/'.$uri)->getStatusCode();
                $this->check(in_array($status, [200, 302], true), "page /{$uri} answers {$status}");
            });
        }
    }

    /** The newest record of the resource a route belongs to. */
    private function recordFor(string $route): ?int
    {
        $resource = explode('.', $route)[3] ?? '';
        $model = match ($resource) {
            'people' => Person::class, 'events' => Event::class, 'households' => Household::class, 'invoices' => Invoice::class, 'automations' => Automation::class,
            'automation-runs' => AutomationRun::class, 'forms' => Form::class, 'event-series' => EventSeries::class, 'mail-templates' => MailTemplate::class,
            'groups' => Group::class, 'mailings' => Mailing::class, 'event-templates' => Event::class, default => null,
        };
        if ($model === null) {
            return null;
        }
        $query = $model::query();
        if ($resource === 'event-templates') {
            $query->where('is_template', true);
        }

        return $query->max('id');
    }
}
