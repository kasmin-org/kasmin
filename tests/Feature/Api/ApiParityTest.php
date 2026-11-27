<?php

namespace Tests\Feature\Api;

use App\Models\ApiToken;
use App\Models\ArchivedInvoice;
use App\Models\AuditEvent;
use App\Models\Automation;
use App\Models\Consent;
use App\Models\ContactPoint;
use App\Models\Event;
use App\Models\EventDivision;
use App\Models\EventDocument;
use App\Models\Form;
use App\Models\Household;
use App\Models\Invoice;
use App\Models\MailAccount;
use App\Models\MailTemplate;
use App\Models\MembershipType;
use App\Models\OutgoingMail;
use App\Models\Participation;
use App\Models\Payment;
use App\Models\Person;
use App\Models\PublicForm;
use App\Models\PublicSubmission;
use App\Models\Refund;
use App\Models\Role;
use App\Models\User;
use App\Models\Webhook;
use App\Support\Finance\Invoices;
use App\Support\Forms\Forms;
use App\Support\Install\Defaults;
use App\Support\Mail\MailLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * X01: what the admin interface can do is also available via REST – families, memberships, duplicates, roles,
 * offers, prices and payment plan, rounds, costs, documents, templates, groups and rooms, lists, finance, mail log, public forms, privacy, reports, own automations, webhooks.
 */
class ApiParityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Person $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app(Defaults::class)->install('en');
        app()->setLocale('en');
        $this->owner = Person::factory()->create(['first_name' => 'Olive', 'last_name' => 'Oak', 'locale' => 'en', 'birth_date' => '1980-01-01']);
        $this->owner->roleAssignments()->create(['role_id' => Role::query()->where('key', 'administrator')->value('id')]);
        $this->user = User::query()->create(['name' => 'Owner', 'email' => 'owner@example.org', 'password' => 'x', 'person_id' => $this->owner->id]);
    }

    /** @param  list<string>  $scopes */
    private function token(array $scopes): string
    {
        return ApiToken::issue($this->user, 'test', $scopes)['plain'];
    }

    private function person(string $first, string $last, int $age = 10): Person
    {
        return Person::factory()->create(['first_name' => $first, 'last_name' => $last, 'locale' => 'en', 'birth_date' => now()->subYears($age)->subMonth()->toDateString()]);
    }

    private function event(int $capacity = 10): Event
    {
        $event = Event::query()->create(['title' => 'Summer camp', 'starts_at' => now()->addMonths(3), 'ends_at' => now()->addMonths(3)->addWeek(), 'status' => 'open']);
        $event->roles()->create(['role_key' => 'child', 'capacity' => $capacity, 'min_age' => 6, 'max_age' => 15, 'price_cents' => 40000]);

        return $event;
    }

    private function participation(Event $event, Person $person, string $status = 'registered'): Participation
    {
        return Participation::query()->create(['event_id' => $event->id, 'person_id' => $person->id, 'role_key' => 'child', 'status' => $status, 'registered_at' => now()]);
    }

    private function invoice(Person $payer): Invoice
    {
        return app(Invoices::class)->single($payer, [['description' => 'Camp', 'amount_cents' => 5000]]);
    }

    public function test_every_new_endpoint_needs_its_scope(): void
    {
        $none = $this->token(['import:write']);
        $p = $this->person('Ann', 'Ash')->public_id;
        $e = $this->event()->public_id;
        $h = Household::factory()->create()->public_id;
        $paths = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/v1/') || str_starts_with($uri, 'api/v1/public/') || in_array($uri, ['api/v1/me', 'api/v1/imports', 'api/v1/imports/people', 'api/v1/imports/people/preview', 'api/v1/imports/people/families', 'api/v1/imports/{batch}/rollback'], true)) {
                continue;
            }
            $path = strtr($uri, ['{person}' => $p, '{event}' => $e, '{household}' => $h, '{key}' => 'participants', '{automation}' => 'deposit_invoices', '{template}' => 'invoice', '{form}' => 'safety_sheet', '{guardian}' => $p, '{type}' => 'member']);
            $path = (string) preg_replace('/\{[a-z_]+\}/', '1', $path);
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $paths[] = [$method, '/'.$path];
            }
        }
        $this->assertGreaterThan(120, count($paths));
        foreach ($paths as $i => [$method, $path]) {
            $none = $i % 100 === 99 ? $this->token(['import:write']) : $none; // the rate limit counts per token
            $status = $this->withToken($none)->json($method, $path)->status();
            $this->assertSame(403, $status, "{$method} {$path} must need a scope");
        }
        // Reading is not enough to write.
        $read = $this->token(['people:read', 'events:read', 'finance:read', 'mail:read', 'automations:read', 'audit:read', 'health:read']);
        foreach ([['post', '/households'], ['patch', "/households/$h"], ['post', "/households/$h/members/$p"], ['post', "/people/$p/memberships"], ['post', '/duplicates/merge'],
            ['post', "/people/$p/roles"], ['delete', "/people/$p/roles/1"], ['post', "/events/$e/offers"], ['post', '/event-series'], ['put', "/events/$e/rounds"], ['post', "/events/$e/costs"], ['post', "/events/$e/documents"], ['post', '/event-templates'], ['delete', '/event-documents/1'], ['post', "/events/$e/divisions"], ['delete', '/units/1'],
            ['post', '/invoices/1/issue'], ['post', '/invoices/1/cancel'], ['post', '/payments/1/assign'], ['post', '/refunds/1/done'], ['post', '/mail-templates/invoice/versions/1/restore'],
            ['post', '/sender-profiles'], ['post', '/public-submissions/1/accept'], ['post', "/people/$p/anonymise"], ['post', '/automations'], ['delete', '/automations/deposit_invoices'],
            ['get', '/webhooks'], ['post', '/webhooks']] as [$method, $path]) {
            $this->withToken($read)->json($method, '/api/v1'.$path)->assertForbidden();
        }
        // The new scopes are separate: writing people does not give roles or anonymise.
        $write = $this->token(['people:read', 'people:write', 'settings:write', 'audit:read']);
        $this->withToken($write)->postJson("/api/v1/people/$p/roles", ['role' => 'counsellor'])->assertForbidden();
        $this->withToken($write)->postJson("/api/v1/people/$p/anonymise", ['reason' => 'asked'])->assertForbidden();
        $this->assertContains('roles:write', ApiToken::SCOPES);
        $this->assertContains('privacy:write', ApiToken::SCOPES);
        foreach (['en', 'de'] as $locale) {
            foreach (ApiToken::SCOPES as $scope) {
                $this->assertNotSame('api.scope_help.'.$scope, __('api.scope_help.'.$scope, [], $locale), "{$scope} has no help text ({$locale})");
            }
        }
    }

    public function test_events_bus_cancel_reason_offers_reserve_places_series_and_schedule(): void
    {
        $token = $this->token(['events:read', 'events:write', 'people:read']);
        $series = $this->withToken($token)->postJson('/api/v1/event-series', ['name' => 'Summer camps'])->assertCreated()->assertJsonMissingPath('data.type')->json('data.id');
        $this->withToken($token)->patchJson("/api/v1/event-series/$series", ['name' => 'Camps'])->assertOk()->assertJsonPath('data.name', 'Camps');
        $this->withToken($token)->postJson('/api/v1/event-series', [])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->withToken($token)->getJson('/api/v1/event-series')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.events', 0);

        // Bus: a booked bus needs its seats, as in the form.
        $dates = ['title' => 'Camp', 'starts_at' => now()->addMonths(2)->toDateString(), 'ends_at' => now()->addMonths(2)->addWeek()->toDateString(), 'series_id' => $series];
        $this->withToken($token)->postJson('/api/v1/events', $dates + ['bus_booked' => true])->assertUnprocessable()->assertJsonValidationErrors('bus_seats');
        $id = $this->withToken($token)->postJson('/api/v1/events', $dates + ['bus_booked' => true, 'bus_seats' => 1, 'roles' => [['role' => 'child', 'capacity' => 1, 'reserve_places' => 0]]])
            ->assertCreated()->assertJsonPath('data.bus_booked', true)->assertJsonPath('data.bus_seats', 1)->assertJsonPath('data.bus.riders', 0)->json('data.id');
        $event = Event::query()->where('public_id', $id)->firstOrFail();
        $ann = $this->person('Ann', 'Ash');
        $ben = $this->person('Ben', 'Birch');
        $first = $this->participation($event, $ann);
        $this->participation($event, $ben);
        $this->withToken($token)->getJson("/api/v1/events/$id")->assertOk()->assertJsonPath('data.bus.riders', 2)->assertJsonPath('data.bus.over', 1)->assertJsonPath('data.bus.free', 0);
        $this->withToken($token)->patchJson("/api/v1/events/$id", ['bus_booked' => false])->assertOk()->assertJsonPath('data.bus.booked', false);

        // Cancel with a reason; a reason without cancelling and cancelling twice are refused.
        $this->withToken($token)->patchJson("/api/v1/participations/{$first->id}", ['status' => 'confirmed', 'reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->withToken($token)->patchJson("/api/v1/participations/{$first->id}", ['status' => 'cancelled', 'reason' => 'Moved away'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('Moved away', $first->refresh()->getAttribute('cancel_reason'));
        $this->withToken($token)->patchJson("/api/v1/participations/{$first->id}", ['status' => 'cancelled'])->assertUnprocessable();

        // Offer a place out of turn: reason and date are required, the family gets the mail of the interface.
        $event->roles()->update(['capacity' => 3, 'reserve_places' => 1]);
        $cleo = $this->person('Cleo', 'Cedar');
        $cleo->contactPoints()->create(['type' => 'email', 'value' => 'cleo@example.org', 'is_primary' => true]);
        $offer = ['person' => $cleo->public_id, 'role' => 'child', 'until' => now()->addWeek()->toDateString()];
        $this->withToken($token)->postJson("/api/v1/events/$id/offers", $offer)->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->withToken($token)->postJson("/api/v1/events/$id/offers", $offer + ['reason' => 'Sibling of a counsellor'])->assertCreated()->assertJsonPath('data.status', 'offered')->assertJsonPath('data.person', $cleo->public_id);
        $this->assertSame('Sibling of a counsellor', Participation::query()->where('person_id', $cleo->id)->value('offer_reason'));
        $this->assertSame(1, AuditEvent::query()->where('action', 'participation.offered')->where('actor_type', 'api')->count());
        $this->assertSame(1, OutgoingMail::query()->where('to_address', 'cleo@example.org')->count());
        $this->withToken($token)->postJson("/api/v1/events/$id/offers", $offer + ['reason' => 'Again'])->assertUnprocessable(); // already has the offer

        // Reserve place for somebody on the waitlist, with a reason.
        $dana = $this->participation($event, $this->person('Dana', 'Dogwood'), 'waitlist');
        $this->withToken($token)->postJson("/api/v1/participations/{$dana->id}/reserve-place", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->withToken($token)->postJson("/api/v1/participations/{$dana->id}/reserve-place", ['reason' => 'Hardship'])->assertOk()->assertJsonPath('data.status', 'registered');
        $this->withToken($token)->postJson("/api/v1/participations/{$dana->id}/reserve-place", ['reason' => 'Again'])->assertUnprocessable();
        $event->roles()->update(['reserve_places' => 2]);
        $this->withToken($token)->postJson("/api/v1/events/$id/reserve-places/release", ['role' => 'child', 'count' => 5])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->withToken($token)->postJson("/api/v1/events/$id/reserve-places/release", ['role' => 'child', 'count' => 5, 'reason' => 'Not needed'])->assertOk()->assertJsonPath('data.reserve_left', 0);

        $schedule = $this->withToken($token)->getJson("/api/v1/events/$id/schedule")->assertOk()->json('data');
        $this->assertContains('date', array_column($schedule, 'kind'));
        $this->assertNotEmpty(array_filter($schedule, fn (array $i) => $i['kind'] === 'automation' && is_string($i['automation'])));
    }

    public function test_event_prices_payment_plan_rounds_costs_documents_and_templates(): void
    {
        Storage::fake('local');
        $token = $this->token(['events:read', 'events:write']);
        $form = Form::query()->create(['key' => 'camp_questions', 'name' => ['en' => 'Camp questions', 'de' => 'Fragen'], 'kind' => 'registration']);
        $type = MembershipType::query()->orderBy('id')->firstOrFail();
        $base = ['title' => 'Camp', 'starts_at' => '2027-07-10', 'ends_at' => '2027-07-17'];
        $this->withToken($token)->postJson('/api/v1/events', $base + ['sibling_mode' => 'half'])->assertUnprocessable()->assertJsonValidationErrors('sibling_mode');
        $this->withToken($token)->postJson('/api/v1/events', $base + ['currency' => 'USD'])->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->withToken($token)->postJson('/api/v1/events', $base + ['prices' => [['role' => 'child', 'min_age' => 12, 'max_age' => 8, 'price' => 300]]])->assertUnprocessable()->assertJsonValidationErrors('prices.0.max_age');
        $this->withToken($token)->postJson('/api/v1/events', $base + ['installments' => [['key' => 'deposit', 'amount' => 100]]])->assertUnprocessable()->assertJsonValidationErrors('installments.0.label');
        $this->withToken($token)->postJson('/api/v1/events', $base + ['installments' => [['key' => 'fee', 'label' => ['de' => 'Beitrag', 'en' => 'Fee'], 'kind' => 'member']]])->assertUnprocessable()->assertJsonValidationErrors('installments.0.membership_type');
        $this->withToken($token)->postJson('/api/v1/events', $base + ['roles' => [['role' => 'child', 'registration_form' => 'safety_sheet']]])->assertUnprocessable()->assertJsonValidationErrors('roles.0.registration_form');
        $id = $this->withToken($token)->postJson('/api/v1/events', $base + [
            'roles' => [['role' => 'child', 'capacity' => 20, 'price' => 400, 'registration_form' => 'camp_questions']],
            'sibling_mode' => 'staggered', 'sibling_order' => 'registration', 'sibling_amount' => 25.5, 'sibling_cap_position' => 3, 'reminder_days_before' => [7, 2],
            'cancellation_fee' => 50, 'cancellation_text' => ['de' => 'Storno bis Mai kostenlos.', 'en' => 'Free until May.'],
            'prices' => [['role' => 'child', 'max_age' => 9, 'price' => 350], ['role' => 'child', 'until_date' => '2027-03-01', 'price' => 380]],
            'installments' => [
                ['key' => 'deposit', 'label' => ['de' => 'Anzahlung', 'en' => 'Deposit'], 'kind' => 'fixed', 'amount' => 100, 'due_days' => 14, 'refundable' => true, 'refund_until_days' => 30, 'confirms_place' => true],
                ['key' => 'fee', 'label' => ['de' => 'Beitrag', 'en' => 'Fee'], 'kind' => 'member', 'membership_type' => $type->key],
                ['key' => 'rest', 'label' => ['de' => 'Rest', 'en' => 'Balance'], 'kind' => 'rest', 'due_date' => '2027-06-01', 'discounts_apply' => true],
            ],
        ])->assertCreated()->assertJsonPath('data.currency', 'EUR')->assertJsonPath('data.sibling_mode', 'staggered')->assertJsonPath('data.sibling_amount', 25.5)->assertJsonPath('data.sibling_cap_position', 3)
            ->assertJsonPath('data.reminder_days_before', [7, 2])->assertJsonPath('data.cancellation_fee', 50)->assertJsonPath('data.cancellation_text', 'Free until May.')
            ->assertJsonPath('data.roles.0.registration_form', 'camp_questions')->assertJsonCount(2, 'data.prices')->assertJsonPath('data.prices.0.max_age', 9)->assertJsonPath('data.prices.1.until_date', '2027-03-01')
            ->assertJsonCount(3, 'data.installments')->assertJsonPath('data.installments.0.confirms_place', true)->assertJsonPath('data.installments.0.refund_until_days', 30)
            ->assertJsonPath('data.installments.1.membership_type', $type->key)->assertJsonPath('data.installments.2.due_date', '2027-06-01')->json('data.id');
        $event = Event::query()->where('public_id', $id)->firstOrFail();
        $this->assertSame($form->id, $event->roles()->value('registration_form_id'));
        $this->assertSame(2550, $event->sibling_amount_cents);

        // PATCH: prices are replaced, instalments go by key; an instalment with invoices cannot be removed.
        $this->withToken($token)->patchJson("/api/v1/events/$id", ['prices' => [['role' => 'child', 'price' => 390]], 'reminder_days_before' => [], 'roles' => [['role' => 'child', 'registration_form' => null]],
            'installments' => [['key' => 'deposit', 'amount' => 120], ['key' => 'rest']]])
            ->assertOk()->assertJsonCount(1, 'data.prices')->assertJsonPath('data.reminder_days_before', null)->assertJsonPath('data.roles.0.registration_form', null)->assertJsonPath('data.roles.0.capacity', 20)
            ->assertJsonCount(2, 'data.installments')->assertJsonPath('data.installments.0.amount', 120)->assertJsonPath('data.installments.0.label', 'Deposit')->assertJsonPath('data.installments.0.confirms_place', true);
        Invoice::query()->create(['event_id' => $event->id, 'installment_key' => 'deposit', 'payer_name' => 'Alex Ash', 'total_cents' => 12000]);
        $this->withToken($token)->patchJson("/api/v1/events/$id", ['installments' => [['key' => 'rest']]])->assertUnprocessable()->assertJsonValidationErrors('installments');
        $this->assertSame(2, $event->installments()->count());
        $this->withToken($token)->patchJson("/api/v1/events/$id", ['installments' => [['key' => 'deposit', 'amount' => 150], ['key' => 'rest']]])->assertOk()->assertJsonPath('data.installments.0.amount', 150); // as in the form

        // Registration rounds.
        $rounds = "/api/v1/events/$id/rounds";
        $this->withToken($token)->putJson($rounds, [])->assertUnprocessable()->assertJsonValidationErrors('rounds');
        $this->withToken($token)->putJson($rounds, ['rounds' => [['role' => 'child', 'kind' => 'returning', 'opens_at' => '2027-01-10 08:00']]])->assertUnprocessable()->assertJsonValidationErrors('rounds.0.closes_at');
        $this->withToken($token)->putJson($rounds, ['rounds' => [['role' => 'child', 'kind' => 'open', 'opens_at' => '2027-02-01 08:00', 'closes_at' => '2027-01-01 08:00']]])->assertUnprocessable();
        $this->withToken($token)->putJson($rounds, ['rounds' => [['id' => 999999, 'role' => 'child', 'kind' => 'open', 'opens_at' => '2027-02-01 08:00']]])->assertUnprocessable()->assertJsonValidationErrors('rounds.0.id');
        $saved = $this->withToken($token)->putJson($rounds, ['rounds' => [
            ['role' => 'child', 'kind' => 'returning', 'mode' => 'payment', 'opens_at' => '2027-01-10 08:00', 'closes_at' => '2027-01-24 20:00'],
            ['role' => 'child', 'kind' => 'open', 'mode' => 'payment', 'min_events' => 3, 'opens_at' => '2027-02-01 08:00'],
        ]])->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.mode', 'payment')->assertJsonPath('data.1.mode', 'accept')->assertJsonPath('data.1.min_events', 0)->json('data');
        $this->withToken($token)->putJson($rounds, ['rounds' => [['id' => $saved[1]['id'], 'role' => 'child', 'kind' => 'open', 'opens_at' => '2027-02-02 08:00']]])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $saved[1]['id']);
        $this->withToken($token)->getJson($rounds)->assertOk()->assertJsonCount(1, 'data');

        // Costs need the finance scopes.
        $costs = "/api/v1/events/$id/costs";
        $this->withToken($token)->getJson($costs)->assertForbidden();
        $this->withToken($this->token(['finance:read']))->postJson($costs, ['label' => 'Bus'])->assertForbidden();
        $finance = $this->token(['finance:read', 'finance:write']);
        $this->withToken($finance)->postJson($costs, ['label' => 'Bus', 'category' => 'fuel', 'amount' => 900])->assertUnprocessable()->assertJsonValidationErrors('category');
        $cost = $this->withToken($finance)->postJson($costs, ['label' => 'Bus', 'category' => 'transport', 'amount' => 900])->assertCreated()->assertJsonPath('total', 900)->json('data.0.id');
        $this->withToken($finance)->postJson($costs, ['label' => 'Food', 'amount' => 1200.5])->assertCreated()->assertJsonPath('total', 2100.5);
        $this->withToken($finance)->patchJson("/api/v1/costs/$cost", ['amount' => -1])->assertUnprocessable();
        $this->withToken($finance)->patchJson("/api/v1/costs/$cost", ['amount' => 950])->assertOk()->assertJsonPath('data.0.amount', 950)->assertJsonPath('data.0.category', 'transport');
        $this->withToken($finance)->deleteJson("/api/v1/costs/$cost")->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($finance)->getJson($costs)->assertOk()->assertJsonPath('total', 1200.5);

        // Documents: the file types and the size limit of the form.
        $documents = "/api/v1/events/$id/documents";
        $pdf = UploadedFile::fake()->createWithContent('packing.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF");
        $this->withToken($token)->post($documents, ['title' => 'Packing list'], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->withToken($token)->post($documents, ['title' => 'Script', 'file' => UploadedFile::fake()->createWithContent('run.php', '<?php echo 1;')], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->withToken($token)->post($documents, ['title' => 'Big', 'file' => UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf')], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->withToken($token)->post($documents, ['title' => 'Packing list', 'visibility' => 'everybody', 'file' => $pdf], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('visibility');
        $document = $this->withToken($token)->post($documents, ['title' => 'Packing list', 'file' => $pdf], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.0.visibility', 'participants')->assertJsonPath('data.0.type', 'pdf')->json('data.0.id');
        $stored = EventDocument::query()->whereKey((int) $document)->firstOrFail();
        $this->assertStringStartsWith(EventDocument::DIRECTORY.'/', $stored->path);
        Storage::disk('local')->assertExists($stored->path);
        $this->withToken($token)->getJson($documents)->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($this->token(['people:read']))->get("/api/v1/event-documents/$document/download", ['Accept' => 'application/json'])->assertForbidden();
        $download = $this->withToken($this->token(['events:read']))->get("/api/v1/event-documents/$document/download")->assertOk();
        $this->assertStringContainsString('Packing-list.pdf', (string) $download->headers->get('Content-Disposition'));

        // Templates: from an event, an event from a template, change, delete.
        $this->withToken($token)->postJson('/api/v1/event-templates', ['event' => $id])->assertUnprocessable()->assertJsonValidationErrors('title');
        $template = $this->withToken($token)->postJson('/api/v1/event-templates', ['event' => $id, 'title' => 'Summer camp'])->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonCount(2, 'data.installments')->json('data.id');
        $this->assertContains($template, array_column((array) $this->withToken($token)->getJson('/api/v1/event-templates')->assertOk()->json('data'), 'id'));
        $this->withToken($token)->getJson('/api/v1/events')->assertOk()->assertJsonPath('meta.total', 1); // a template is not an event
        $this->withToken($token)->getJson("/api/v1/events/$template")->assertNotFound();
        $this->withToken($token)->patchJson("/api/v1/event-templates/$template", ['status' => 'open'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->withToken($token)->patchJson("/api/v1/event-templates/$template", ['public_page' => true])->assertUnprocessable();
        $this->withToken($token)->patchJson("/api/v1/event-templates/$template", ['location' => 'Lake house', 'cancellation_fee' => 60])->assertOk()->assertJsonPath('data.location', 'Lake house')->assertJsonPath('data.cancellation_fee', 60);
        $this->withToken($token)->postJson('/api/v1/events', ['template' => $template, 'title' => 'Summer camp 2028'])->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        $this->withToken($token)->postJson('/api/v1/events', ['template' => $id, 'title' => 'Summer camp 2028', 'starts_at' => '2028-07-08'])->assertNotFound(); // an event is not a template
        $next = $this->withToken($token)->postJson('/api/v1/events', ['template' => $template, 'title' => 'Summer camp 2028', 'starts_at' => '2028-07-08'])->assertCreated()
            ->assertJsonPath('data.title', 'Summer camp 2028')->assertJsonPath('data.status', 'draft')->assertJsonPath('data.location', 'Lake house')->assertJsonPath('data.cancellation_fee', 60)
            ->assertJsonCount(2, 'data.installments')->assertJsonPath('data.roles.0.capacity', 20)->json('data');
        $this->assertStringStartsWith('2028-07-08', $next['starts_at']);
        $this->assertStringStartsWith('2028-07-15', $next['ends_at']);
        $this->withToken($token)->getJson("/api/v1/events/{$next['id']}/rounds")->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($token)->getJson("/api/v1/events/{$next['id']}/documents")->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($token)->postJson('/api/v1/events', ['copy_of' => $id, 'title' => 'Autumn camp', 'starts_at' => '2027-10-01'])->assertCreated()->assertJsonPath('data.title', 'Autumn camp');
        $this->assertSame(3, AuditEvent::query()->where('action', 'event.copied')->where('actor_type', 'api')->count());
        $this->withToken($token)->deleteJson("/api/v1/event-templates/$template")->assertOk();
        $this->withToken($token)->deleteJson("/api/v1/event-templates/$template")->assertNotFound();
        $this->withToken($token)->getJson("/api/v1/events/{$next['id']}")->assertOk();

        // Copies share the file: it goes with the last document.
        $this->withToken($token)->deleteJson("/api/v1/event-documents/$document")->assertOk()->assertJsonCount(0, 'data');
        Storage::disk('local')->assertExists($stored->path);
        EventDocument::query()->get()->each->delete();
        Storage::disk('local')->assertMissing($stored->path);
    }

    public function test_groups_and_rooms_of_an_event(): void
    {
        $token = $this->token(['events:read', 'events:write']);
        $event = $this->event();
        $people = [];
        foreach ([['Ann', 'Ash', 9], ['Ben', 'Birch', 10], ['Cleo', 'Cedar', 11], ['Dana', 'Dogwood', 12]] as [$first, $last, $age]) {
            $people[] = $this->participation($event, $this->person($first, $last, $age));
        }
        $url = "/api/v1/events/{$event->public_id}/divisions";
        $this->withToken($token)->postJson($url, ['kind' => 'tents', 'name' => 'Tents', 'units' => 2])->assertUnprocessable()->assertJsonValidationErrors('kind');
        $this->withToken($token)->postJson($url, ['kind' => 'groups', 'name' => 'Groups', 'units' => 2, 'options' => ['colour' => 'red']])->assertUnprocessable();
        $division = $this->withToken($token)->postJson($url, ['kind' => 'groups', 'name' => 'Groups', 'units' => 2, 'capacity' => 2, 'options' => ['separate_gender' => true, 'siblings' => 'any']])
            ->assertCreated()->assertJsonCount(2, 'data.units')->assertJsonCount(4, 'data.not_placed')->assertJsonPath('data.options.separate_gender', true)
            ->assertJsonPath('data.options.balance_gender', false)->assertJsonPath('data.options.roles', ['child', 'junior_counsellor'])->json('data');
        $this->assertNotEmpty($division['warnings']);
        $id = $division['id'];
        [$one, $two] = array_column($division['units'], 'id');
        $this->assertSame(1, AuditEvent::query()->where('action', 'event.division_created')->where('actor_type', 'api')->count());

        $this->withToken($token)->postJson("/api/v1/divisions/$id/propose", ['restart' => 'perhaps'])->assertUnprocessable();
        $this->withToken($token)->postJson("/api/v1/divisions/$id/propose")->assertOk()->assertJsonPath('placed', 4)->assertJsonCount(0, 'data.not_placed');
        $this->withToken($token)->putJson("/api/v1/divisions/$id/members/{$people[0]->id}", ['unit' => null])->assertOk()->assertJsonCount(1, 'data.not_placed');
        $this->withToken($token)->putJson("/api/v1/divisions/$id/members/{$people[0]->id}", ['unit' => 999999])->assertUnprocessable();
        $this->withToken($token)->putJson("/api/v1/divisions/$id/members/{$people[0]->id}", [])->assertUnprocessable()->assertJsonValidationErrors('unit');
        $this->withToken($token)->postJson("/api/v1/divisions/$id/propose", ['restart' => true])->assertOk()->assertJsonPath('placed', 4);

        $this->withToken($token)->patchJson("/api/v1/units/$one", ['name' => 'Foxes', 'note' => 'Upper field', 'capacity' => 3])->assertOk()->assertJsonPath('data.units.0.name', 'Foxes')->assertJsonPath('data.units.0.capacity', 3);
        $this->withToken($token)->patchJson("/api/v1/units/$one", ['capacity' => 0])->assertUnprocessable()->assertJsonValidationErrors('capacity');
        $this->withToken($token)->deleteJson("/api/v1/units/$two")->assertUnprocessable(); // not empty
        $three = $this->withToken($token)->postJson("/api/v1/divisions/$id/units")->assertCreated()->assertJsonCount(3, 'data.units')->json('data.units.2.id');
        $this->withToken($token)->deleteJson("/api/v1/units/$three")->assertOk()->assertJsonCount(2, 'data.units');
        $this->withToken($token)->getJson($url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.units.0.note', 'Upper field');

        $this->withToken($token)->deleteJson("/api/v1/divisions/$id")->assertOk();
        $this->assertSame(0, EventDivision::query()->count());
        $this->assertSame(4, Participation::query()->count());
    }

    public function test_lists_of_an_event_and_confidential_ones_need_the_health_scope_and_are_audited(): void
    {
        $event = $this->event();
        $ann = $this->person('Ann', 'Ash');
        $this->participation($event, $ann);
        app(Forms::class)->save(Form::query()->where('key', 'safety_sheet')->with('fields')->firstOrFail(), $ann, ['allergies' => 'Nuts', 'swimmer' => 'yes'], null);
        $url = "/api/v1/events/{$event->public_id}/lists";
        $normal = $this->token(['events:read']);
        $keys = array_column($this->withToken($normal)->getJson($url)->assertOk()->json('data'), 'key');
        $this->assertContains('participants', $keys);
        $this->assertNotContains('medical', $keys);
        $list = $this->withToken($normal)->getJson("$url/participants")->assertOk()->assertJsonPath('data.confidential', false)->json('data');
        $this->assertNotEmpty($list['headers']);
        $this->assertStringContainsString('Ash', (string) json_encode($list['rows']));
        $this->withToken($normal)->getJson("$url/participants?role=cook")->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->withToken($normal)->getJson("$url/unknown_list")->assertNotFound();
        foreach (['medical', 'kitchen', 'emergency', 'medication'] as $key) {
            $this->withToken($normal)->getJson("$url/$key")->assertForbidden();
        }
        $this->assertSame(0, AuditEvent::query()->where('action', 'list.viewed')->count());

        $health = $this->token(['events:read', 'health:read']);
        $this->assertContains('medical', array_column($this->withToken($health)->getJson($url)->assertOk()->json('data'), 'key'));
        $medical = $this->withToken($health)->getJson("$url/medical")->assertOk()->assertJsonPath('data.confidential', true);
        $this->assertStringContainsString('Nuts', (string) $medical->getContent());
        $entry = AuditEvent::query()->where('action', 'list.viewed')->sole();
        $this->assertSame('api', $entry->actor_type);
        $this->assertSame('medical', $entry->meta['list'] ?? null);
        $this->assertSame($event->id, (int) $entry->subject_id);
        // Health data alone does not open the lists.
        $this->withToken($this->token(['health:read']))->getJson("$url/medical")->assertForbidden();
    }

    public function test_families_members_and_invoice_recipient(): void
    {
        $token = $this->token(['people:read', 'people:write']);
        $this->withToken($token)->postJson('/api/v1/households', ['street' => 'Oak Lane 1'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->withToken($token)->postJson('/api/v1/households', ['name' => 'Family Ash', 'postal_code' => '10<0'])->assertUnprocessable()->assertJsonValidationErrors('postal_code');
        $id = $this->withToken($token)->postJson('/api/v1/households', ['name' => 'Family Ash', 'street' => 'Oak Lane 1', 'postal_code' => '1010', 'city' => 'Vienna', 'country' => 'at'])
            ->assertCreated()->assertJsonPath('data.country', 'AT')->assertJsonMissingPath('data.locale')->assertJsonPath('data.invoice_recipient', null)->json('data.id');
        $url = "/api/v1/households/$id";
        $alex = $this->person('Alex', 'Ash', 40);
        $ann = $this->person('Ann', 'Ash');
        $this->withToken($token)->postJson("$url/members/{$alex->public_id}", ['role' => 'uncle'])->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->withToken($token)->postJson("$url/members/{$alex->public_id}", ['role' => 'guardian'])->assertCreated()->assertJsonCount(1, 'data.members');
        $this->withToken($token)->postJson("$url/members/{$alex->public_id}", ['role' => 'guardian'])->assertOk()->assertJsonCount(1, 'data.members');
        $this->withToken($token)->postJson("$url/members/{$ann->public_id}", ['role' => 'child'])->assertCreated()->assertJsonCount(2, 'data.members');
        // As in the interface: the legal guardians of the family are the legal guardians of its children.
        $this->assertTrue($ann->guardians()->whereKey($alex->id)->exists());

        // Only a legal guardian of the family can get the invoices.
        $this->withToken($token)->patchJson($url, ['invoice_recipient' => $ann->public_id])->assertUnprocessable()->assertJsonValidationErrors('invoice_recipient');
        $this->withToken($token)->patchJson($url, ['invoice_recipient' => $this->owner->public_id])->assertUnprocessable();
        $this->withToken($token)->patchJson($url, ['invoice_recipient' => $alex->public_id, 'city' => 'Graz'])->assertOk()->assertJsonPath('data.invoice_recipient', $alex->public_id)->assertJsonPath('data.city', 'Graz');
        $this->withToken($token)->getJson($url)->assertOk()->assertJsonPath('data.invoice_recipient', $alex->public_id);
        $this->withToken($token)->postJson('/api/v1/households', ['name' => 'Family Birch', 'invoice_recipient' => $alex->public_id])->assertUnprocessable();

        $this->withToken($token)->deleteJson("$url/members/{$alex->public_id}")->assertOk()->assertJsonCount(1, 'data.members')->assertJsonPath('data.invoice_recipient', null);
        $this->withToken($token)->deleteJson("$url/members/{$alex->public_id}")->assertNotFound();
        $this->assertTrue($ann->guardians()->whereKey($alex->id)->exists(), 'the guardianship stays');
        $this->assertSame(2, AuditEvent::query()->where('action', 'household.member_added')->count());
    }

    public function test_memberships_and_duplicates(): void
    {
        $token = $this->token(['people:read', 'people:write']);
        $ann = $this->person('Ann', 'Ash');
        $type = MembershipType::query()->orderBy('id')->firstOrFail();
        $url = "/api/v1/people/{$ann->public_id}/memberships";
        $this->withToken($token)->postJson($url, ['type' => 'no-such-type'])->assertUnprocessable()->assertJsonValidationErrors('type');
        $id = $this->withToken($token)->postJson($url, ['type' => $type->key, 'starts_on' => '2026-01-01'])->assertCreated()->assertJsonPath('data.0.type', $type->key)->assertJsonPath('data.0.ends_on', null)->json('data.0.id');
        $this->withToken($token)->postJson($url, ['type' => $type->key])->assertUnprocessable()->assertJsonValidationErrors('type'); // one running membership per type
        $this->withToken($token)->patchJson("$url/$id", ['ends_on' => '2025-12-31'])->assertUnprocessable()->assertJsonValidationErrors('ends_on');
        $this->withToken($token)->patchJson("$url/$id", ['ends_on' => '2026-12-31'])->assertOk()->assertJsonPath('data.0.ends_on', '2026-12-31');
        $this->withToken($token)->postJson($url, ['type' => $type->key])->assertCreated()->assertJsonCount(2, 'data');
        $this->withToken($token)->getJson($url)->assertOk()->assertJsonCount(2, 'data');

        // Duplicates: the same name and date of birth.
        $twin = Person::factory()->create(['first_name' => 'Ann', 'last_name' => 'Ash', 'locale' => 'de', 'birth_date' => $ann->birth_date]);
        $other = Person::factory()->create(['first_name' => 'Ben', 'last_name' => 'Birch', 'birth_date' => '2015-05-05']);
        $copy = Person::factory()->create(['first_name' => 'Ben', 'last_name' => 'Birch', 'birth_date' => '2015-05-05']);
        $this->withToken($token)->getJson('/api/v1/duplicates')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.reason', 'name_birth');
        $this->withToken($token)->postJson('/api/v1/duplicates/dismiss', ['first' => $other->public_id, 'second' => $copy->public_id])->assertOk();
        $this->withToken($token)->getJson('/api/v1/duplicates')->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($token)->postJson('/api/v1/duplicates/merge', ['keep' => $ann->public_id, 'duplicate' => $ann->public_id])->assertUnprocessable()->assertJsonValidationErrors('duplicate');
        $this->withToken($token)->postJson('/api/v1/duplicates/merge', ['keep' => $ann->public_id, 'duplicate' => $twin->public_id, 'fields' => ['shoe_size' => 'keep']])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/duplicates/merge', ['keep' => $ann->public_id, 'duplicate' => $twin->public_id, 'fields' => ['gender' => 'duplicate']])->assertOk()->assertJsonMissingPath('data.locale');
        $this->assertNull(Person::query()->find($twin->id));
        $this->assertSame(1, AuditEvent::query()->where('action', 'person.merged')->where('actor_type', 'api')->count());
        // A merge never gives somebody more rights.
        $shadow = Person::factory()->create(['first_name' => 'Olive', 'last_name' => 'Oak']);
        $this->withToken($token)->postJson('/api/v1/duplicates/merge', ['keep' => $shadow->public_id, 'duplicate' => $this->owner->public_id])->assertUnprocessable();
        $this->assertNotNull(Person::query()->find($this->owner->id));
    }

    public function test_roles_and_the_administrator_role_is_never_assignable(): void
    {
        $token = $this->token(['people:read', 'roles:write']);
        $ann = $this->person('Ann', 'Ash', 30);
        $event = $this->event();
        $roles = array_column((array) $this->withToken($token)->getJson('/api/v1/roles')->assertOk()->json('data'), null, 'key');
        $this->assertFalse($roles['administrator']['assignable']);
        $this->assertTrue($roles['counsellor']['assignable']);
        $url = "/api/v1/people/{$ann->public_id}/roles";
        $this->withToken($token)->postJson($url, ['role' => 'emperor'])->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->withToken($token)->postJson($url, ['role' => 'administrator'])->assertForbidden();
        $this->assertSame([], $ann->activeRoleKeys());

        $own = Role::query()->where('scope', 'own')->where('key', '!=', 'administrator')->value('key');
        $all = Role::query()->where(fn ($q) => $q->whereNull('scope')->orWhere('scope', '!=', 'own'))->where('key', '!=', 'administrator')->value('key');
        $this->assertNotNull($own);
        $this->assertNotNull($all);
        $this->withToken($token)->postJson($url, ['role' => $all, 'event' => $event->public_id])->assertUnprocessable()->assertJsonValidationErrors('event');
        $id = $this->withToken($token)->postJson($url, ['role' => $own, 'event' => $event->public_id, 'ends_on' => now()->addYear()->toDateString()])
            ->assertCreated()->assertJsonPath('data.0.role', $own)->assertJsonPath('data.0.event', $event->public_id)->assertJsonPath('data.0.starts_on', now()->toDateString())->json('data.0.id');
        $this->assertSame([$own], $ann->activeRoleKeys());
        $this->withToken($token)->getJson($url)->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($token)->deleteJson("$url/$id")->assertOk()->assertJsonCount(0, 'data');

        // The administrator role of somebody cannot be taken either.
        $owner = "/api/v1/people/{$this->owner->public_id}/roles";
        $assignment = $this->withToken($token)->getJson($owner)->assertOk()->assertJsonPath('data.0.role', 'administrator')->json('data.0.id');
        $this->withToken($token)->deleteJson("$owner/$assignment")->assertForbidden();
        $this->assertSame(['administrator'], $this->owner->activeRoleKeys());
    }

    public function test_finance_issue_send_cancel_payments_refunds_and_archive(): void
    {
        $token = $this->token(['finance:read', 'finance:write']);
        $alex = $this->person('Alex', 'Ash', 40);
        $alex->contactPoints()->create(['type' => 'email', 'value' => 'alex@example.org', 'is_primary' => true]);
        $draft = $this->invoice($alex);
        $this->withToken($token)->postJson("/api/v1/invoices/{$draft->id}/send")->assertUnprocessable();
        $this->withToken($token)->postJson("/api/v1/invoices/{$draft->id}/cancel", ['reason' => 'Wrong'])->assertUnprocessable(); // only issued invoices
        $this->withToken($token)->postJson("/api/v1/invoices/{$draft->id}/issue", ['send' => 'later'])->assertUnprocessable()->assertJsonValidationErrors('send');
        $this->withToken($token)->postJson("/api/v1/invoices/{$draft->id}/issue", ['send' => false])->assertOk()->assertJsonPath('data.status', 'issued')->assertJsonPath('sent', false);
        $this->assertSame(0, OutgoingMail::query()->count());
        $this->withToken($token)->postJson("/api/v1/invoices/{$draft->id}/issue")->assertUnprocessable(); // only drafts
        $this->withToken($token)->postJson("/api/v1/invoices/{$draft->id}/send")->assertStatus(202)->assertJsonPath('sent', true);
        $this->assertSame(1, OutgoingMail::query()->where('to_address', 'alex@example.org')->count());
        $second = $this->invoice($alex);
        $this->withToken($token)->postJson("/api/v1/invoices/{$second->id}/issue")->assertOk()->assertJsonPath('sent', true);
        $this->assertSame(2, OutgoingMail::query()->count());

        // Payments: list, assign, the rest becomes a credit with a refund proposal.
        $payment = Payment::query()->create(['amount_cents' => 6000, 'paid_on' => now()->toDateString(), 'method' => 'bank', 'match' => 'unmatched', 'payer_name' => 'Alex Ash', 'iban' => 'AT611904300234573201']);
        $this->withToken($token)->getJson('/api/v1/payments?match=unmatched')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.amount', 60)->assertJsonMissingPath('data.0.iban');
        $this->withToken($token)->postJson("/api/v1/payments/{$payment->id}/assign")->assertUnprocessable(); // nothing suggested
        $this->withToken($token)->postJson("/api/v1/payments/{$payment->id}/assign", ['invoice' => 'R1'])->assertUnprocessable()->assertJsonValidationErrors('invoice');
        $this->withToken($token)->postJson("/api/v1/payments/{$payment->id}/assign", ['invoice' => $draft->id])->assertOk()->assertJsonPath('data.match', 'manual')->assertJsonPath('data.amount', 50);
        $this->assertSame('paid', $draft->refresh()->status);
        $this->withToken($token)->postJson("/api/v1/payments/{$payment->id}/assign", ['invoice' => $second->id])->assertUnprocessable(); // already assigned

        // Refunds: the overpaid part.
        $refund = $this->withToken($token)->getJson('/api/v1/refunds?status=proposed')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.amount', 10)->json('data.0.id');
        $this->withToken($token)->postJson("/api/v1/refunds/$refund/done", [])->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->withToken($token)->postJson("/api/v1/refunds/$refund/done", ['date' => now()->addDay()->toDateString()])->assertUnprocessable();
        $this->withToken($token)->postJson("/api/v1/refunds/$refund/done", ['date' => now()->toDateString()])->assertOk()->assertJsonPath('data.status', 'done');
        $this->withToken($token)->postJson("/api/v1/refunds/$refund/keep")->assertUnprocessable();
        $this->assertSame(1, AuditEvent::query()->where('action', 'refund.done')->where('actor_type', 'api')->count());

        // Cancelling needs a reason and makes a credit note, as in the interface.
        $this->withToken($token)->postJson("/api/v1/invoices/{$draft->id}/cancel", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->withToken($token)->postJson("/api/v1/invoices/{$draft->id}/cancel", ['reason' => '  '])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertSame('paid', $draft->refresh()->status);
        $this->withToken($token)->postJson("/api/v1/invoices/{$draft->id}/cancel", ['reason' => 'Camp cancelled'])->assertCreated()
            ->assertJsonPath('data.type', 'credit_note')->assertJsonPath('data.total', -50)->assertJsonPath('cancelled.status', 'cancelled');
        $this->assertStringContainsString('Camp cancelled', (string) Invoice::query()->where('type', 'credit_note')->firstOrFail()->lines()->value('description'));
        $kept = Refund::query()->where('status', 'proposed')->sole();
        $this->withToken($token)->postJson("/api/v1/refunds/{$kept->id}/keep")->assertOk()->assertJsonPath('data.status', 'declined');
        $this->withToken($token)->postJson("/api/v1/invoices/{$draft->id}/cancel", ['reason' => 'Again'])->assertUnprocessable();

        ArchivedInvoice::query()->create(['number' => 'OLD-17', 'issued_on' => '2024-03-01', 'payer_name' => 'Alex Ash', 'total_cents' => 12000, 'status' => 'paid']);
        $this->withToken($token)->getJson('/api/v1/invoice-archive?search=OLD')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.total', 120)->assertJsonPath('data.0.has_pdf', false);
        $this->withToken($token)->getJson('/api/v1/invoice-archive?status=lost')->assertUnprocessable();
        foreach (['post', 'patch', 'delete'] as $method) {
            $this->withToken($token)->json($method, '/api/v1/invoice-archive')->assertStatus(405);
        }
    }

    public function test_mail_versions_log_without_personal_links_bad_addresses_and_sender_profiles(): void
    {
        $token = $this->token(['mail:read', 'mail:write']);
        $contents = fn (string $subject) => ['subject' => $subject, 'html' => '<p>Hello</p>'];
        $this->withToken($token)->patchJson('/api/v1/mail-templates/invoice', $contents('First change'))->assertOk();
        $this->withToken($token)->patchJson('/api/v1/mail-templates/invoice', $contents('Second change'))->assertOk();
        $versions = $this->withToken($token)->getJson('/api/v1/mail-templates/invoice/versions')->assertOk()->assertJsonCount(3, 'data')->json('data');
        $this->assertSame('Second change', $versions[0]['subject']);
        $this->assertSame('Owner', $versions[0]['changed_by']);
        $this->withToken($token)->postJson('/api/v1/mail-templates/invoice/versions/999/restore')->assertNotFound();
        $this->withToken($token)->postJson('/api/v1/mail-templates/invoice/versions/'.$versions[1]['version'].'/restore')->assertOk()->assertJsonPath('data.version', $versions[0]['version'] + 1);
        $this->assertSame('First change', MailTemplate::query()->where('key', 'invoice')->firstOrFail()->content('en')?->subject);

        // The mail log holds mails as stored: the personal link is only made when a mail is sent.
        $ann = $this->person('Ann', 'Ash', 30);
        $placeholder = MailLinks::placeholder();
        $stored = OutgoingMail::query()->create(['to_address' => 'ann@example.org', 'person_id' => $ann->id, 'subject' => 'Your login', 'html' => '<a href="'.$placeholder.'">Log in</a>', 'text' => 'Log in: '.$placeholder, 'status' => 'queued']);
        OutgoingMail::query()->create(['to_address' => 'ben@example.org', 'subject' => 'Old', 'html' => 'x', 'status' => 'sent', 'sent_at' => now(),
            'text' => 'Log in: '.url('/login/link/SECRETTOKEN123').' or decide: '.url('/approvals/SECRETAPPROVAL456?x=1')]);
        $this->assertNotSame($placeholder, MailLinks::resolve($stored)['text']);
        $log = $this->withToken($token)->getJson('/api/v1/outgoing-mails')->assertOk()->assertJsonPath('meta.total', 2);
        $body = (string) $log->getContent();
        $this->assertStringNotContainsString('SECRETTOKEN123', $body);
        $this->assertStringNotContainsString('SECRETAPPROVAL456', $body);
        $this->assertStringNotContainsString('<a href', $body);
        foreach ($log->json('data') as $mail) {
            $this->assertArrayNotHasKey('html', $mail);
            $this->assertSame(0, preg_match('#/(login/link|approvals)/(?!'.MailLinks::PLACEHOLDER.')#', $mail['text']), 'only the placeholder may be in a stored mail');
        }
        $this->assertSame(0, DB::table('login_tokens')->count(), 'reading the log makes no login link');
        $this->withToken($token)->getJson('/api/v1/outgoing-mails?status=sent')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.to', 'ben@example.org');
        $this->withToken($token)->getJson("/api/v1/outgoing-mails?person={$ann->public_id}")->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.text', 'Log in: '.$placeholder);
        $this->withToken($token)->getJson('/api/v1/outgoing-mails?status=lost')->assertUnprocessable()->assertJsonValidationErrors('status');

        $point = ContactPoint::query()->create(['person_id' => $ann->id, 'type' => 'email', 'value' => 'ann@example.org', 'is_primary' => true]);
        ContactPoint::query()->whereKey($point->id)->update(['bounced_at' => now(), 'bounce_reason' => 'User unknown']);
        $this->withToken($token)->getJson('/api/v1/bad-addresses')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.address', 'ann@example.org')->assertJsonPath('data.0.reason', 'User unknown');
        $this->withToken($this->token(['mail:read']))->postJson("/api/v1/bad-addresses/{$point->id}/clear")->assertForbidden();
        $this->withToken($token)->postJson("/api/v1/bad-addresses/{$point->id}/clear")->assertOk()->assertJsonPath('data.bounced_at', null);
        $this->withToken($token)->postJson("/api/v1/bad-addresses/{$point->id}/clear")->assertNotFound();

        // Sender profiles: reading with mail:read, changing needs settings:write; no passwords anywhere.
        $account = MailAccount::query()->create(['name' => 'Office', 'smtp_host' => 'smtp.example.org', 'smtp_username' => 'office', 'smtp_password' => 'smtp-secret-1', 'imap_password' => 'imap-secret-2']);
        $profile = ['name' => 'Office', 'from_name' => 'Example Camp', 'from_address' => 'office@example.org', 'mail_account' => $account->id];
        $this->withToken($token)->postJson('/api/v1/sender-profiles', $profile)->assertForbidden();
        $admin = $this->token(['mail:read', 'settings:write']);
        $this->withToken($admin)->postJson('/api/v1/sender-profiles', ['from_address' => 'office'] + $profile)->assertUnprocessable()->assertJsonValidationErrors('from_address');
        $this->withToken($admin)->postJson('/api/v1/sender-profiles', ['mail_account' => 999] + $profile)->assertUnprocessable()->assertJsonValidationErrors('mail_account');
        $id = $this->withToken($admin)->postJson('/api/v1/sender-profiles', $profile)->assertCreated()->assertJsonPath('data.mail_account_name', 'Office')->json('data.id');
        $changed = $this->withToken($admin)->patchJson("/api/v1/sender-profiles/$id", ['reply_to' => 'team@example.org', 'smtp_password' => 'x'])->assertOk()->assertJsonPath('data.reply_to', 'team@example.org');
        $this->withToken($admin)->postJson('/api/v1/sender-profiles', ['from_address' => 'second@example.org'] + $profile)->assertUnprocessable()->assertJsonValidationErrors('from_address'); // an installation has one sender
        $list = $this->withToken($token)->getJson('/api/v1/sender-profiles')->assertOk()->assertJsonCount(1, 'data');
        foreach ([$changed, $list] as $answer) {
            $this->assertStringNotContainsString('secret', (string) $answer->getContent());
            $this->assertStringNotContainsString('password', (string) $answer->getContent());
        }
        $this->assertSame('smtp-secret-1', $account->refresh()->smtp_password);
    }

    public function test_public_forms_and_their_submissions(): void
    {
        $token = $this->token(['people:read', 'people:write']);
        $form = PublicForm::query()->create(['slug' => 'team', 'kind' => 'application', 'title' => ['en' => 'Join the team', 'de' => 'Mitmachen'], 'active' => true, 'needs_approval' => true]);
        $this->withToken($token)->getJson('/api/v1/public-forms')->assertOk()->assertJsonPath('data.0.slug', 'team')->assertJsonPath('data.0.kind', 'application');
        $make = fn (string $first, string $status) => PublicSubmission::query()->create([
            'public_form_id' => $form->id, 'token_hash' => hash('sha256', $first), 'status' => $status, 'expires_at' => now()->addDay(), 'confirmed_at' => now(),
            'data' => ['first_name' => $first, 'last_name' => 'Birch', 'email' => strtolower($first).'@example.org', 'values' => []],
        ]);
        $ben = $make('Ben', 'pending');
        $cleo = $make('Cleo', 'pending');
        $make('Dana', 'unconfirmed');
        $this->withToken($token)->getJson('/api/v1/public-submissions')->assertOk()->assertJsonPath('meta.total', 2); // not the unconfirmed one
        $this->withToken($token)->getJson('/api/v1/public-submissions?status=pending')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.name', 'Cleo Birch')->assertJsonPath('data.0.answers.email', 'cleo@example.org');
        $this->withToken($token)->getJson('/api/v1/public-submissions?status=open')->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->withToken($token)->postJson("/api/v1/public-submissions/{$ben->id}/accept")->assertOk()->assertJsonPath('data.status', 'done')->assertJsonPath('data.answers', null);
        $this->assertSame(1, Person::query()->where('first_name', 'Ben')->where('last_name', 'Birch')->count());
        $this->withToken($token)->postJson("/api/v1/public-submissions/{$ben->id}/accept")->assertUnprocessable();
        $this->withToken($token)->postJson("/api/v1/public-submissions/{$cleo->id}/reject", ['note' => str_repeat('x', 300)])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->withToken($token)->postJson("/api/v1/public-submissions/{$cleo->id}/reject", ['note' => 'Team is complete'])->assertOk()->assertJsonPath('data.status', 'declined')->assertJsonPath('data.note', 'Team is complete');
        $this->assertNull($cleo->refresh()->data);
        $this->assertSame(1, AuditEvent::query()->where('action', 'public_form.declined')->where('actor_type', 'api')->count());
        $this->withToken($token)->postJson('/api/v1/public-submissions/'.PublicSubmission::query()->where('status', 'unconfirmed')->value('id').'/accept')->assertNotFound();
    }

    public function test_privacy_consents_export_and_anonymise(): void
    {
        $read = $this->token(['audit:read', 'people:read']);
        $ann = $this->person('Ann', 'Ash', 30);
        $ann->contactPoints()->create(['type' => 'email', 'value' => 'ann@example.org', 'is_primary' => true]);
        Consent::query()->create(['person_id' => $ann->id, 'kind' => 'newsletter', 'granted' => true, 'source' => 'portal']);
        Consent::query()->create(['person_id' => $ann->id, 'kind' => 'deletion_request', 'granted' => true, 'source' => 'portal']);
        $this->withToken($read)->getJson('/api/v1/consents')->assertOk()->assertJsonPath('meta.total', 2);
        $this->withToken($read)->getJson('/api/v1/consents?kind=newsletter')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.person', $ann->public_id)->assertJsonPath('data.0.source', 'portal');
        $this->withToken($read)->getJson('/api/v1/consents?kind='.str_repeat('x', 40))->assertUnprocessable();
        $this->withToken($read)->getJson('/api/v1/deletion-requests')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.person', $ann->public_id)->assertJsonPath('data.0.plan.delete.contact_points', 1);

        // Export: the same copy as in the interface; with confidential answers only with health:read.
        $url = "/api/v1/people/{$ann->public_id}/export";
        $this->withToken($this->token(['audit:read']))->getJson($url)->assertForbidden();
        $this->withToken($read)->getJson($url)->assertOk()->assertJsonPath('data.person.first_name', 'Ann')->assertJsonPath('data.person.contacts.0.value', 'ann@example.org');
        $this->assertSame('api', AuditEvent::query()->where('action', 'privacy.data_exported')->sole()->meta['source'] ?? null);
        // Emergency contacts of the family are safety data too.
        $family = Household::query()->create(['name' => 'Ash']);
        $family->members()->attach($ann->id, ['role' => 'guardian']);
        $contact = $family->emergencyContacts()->create(['name' => 'Robin Birch', 'relation' => 'aunt', 'phone' => '+43 1 555']);
        $this->withToken($read)->getJson($url)->assertForbidden();
        $contact->delete();
        $this->withToken($read)->getJson($url)->assertOk();
        AuditEvent::query()->where('action', 'privacy.data_exported')->latest('id')->limit(1)->delete();
        $child = $this->person('Cleo', 'Ash');
        DB::table('guardianships')->insert(['guardian_id' => $ann->id, 'child_id' => $child->id, 'created_at' => now(), 'updated_at' => now()]);
        app(Forms::class)->save(Form::query()->where('key', 'safety_sheet')->with('fields')->firstOrFail(), $child, ['allergies' => 'Nuts', 'swimmer' => 'yes'], null);
        $refused = $this->withToken($read)->getJson($url)->assertForbidden();
        $this->assertStringNotContainsString('Nuts', (string) $refused->getContent());
        $this->assertSame(1, AuditEvent::query()->where('action', 'privacy.data_exported')->count());
        $full = $this->withToken($this->token(['audit:read', 'people:read', 'health:read']))->getJson($url)->assertOk();
        $this->assertStringContainsString('Nuts', (string) $full->getContent());

        // Anonymise: own scope, preview, reason required, refused while the person has a role.
        $write = $this->token(['privacy:write']);
        $anonymise = "/api/v1/people/{$ann->public_id}/anonymise";
        $this->withToken($read)->postJson($anonymise, ['reason' => 'Asked for it'])->assertForbidden();
        $this->withToken($write)->postJson($anonymise, [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->withToken($write)->postJson($anonymise, ['preview' => true])->assertOk()->assertJsonPath('data.anonymised', false)->assertJsonPath('data.plan.delete.contact_points', 1)->assertJsonPath('data.refused', []);
        $this->assertNull($ann->refresh()->getAttribute('anonymised_at'));
        $role = $ann->roleAssignments()->create(['role_id' => Role::query()->where('key', 'counsellor')->value('id')]);
        $this->withToken($write)->postJson($anonymise, ['preview' => true])->assertOk()->assertJsonCount(1, 'data.refused');
        $this->withToken($write)->postJson($anonymise, ['reason' => 'Asked for it'])->assertUnprocessable()->assertJsonCount(1, 'reasons');
        $this->assertNull($ann->refresh()->getAttribute('anonymised_at'));
        $role->delete();
        $this->withToken($write)->postJson($anonymise, ['reason' => 'Asked for it'])->assertOk()->assertJsonPath('data.anonymised', true);
        $this->assertNotNull($ann->refresh()->getAttribute('anonymised_at'));
        $this->assertSame(0, $ann->contactPoints()->count());
        $entry = AuditEvent::query()->where('action', 'privacy.anonymised')->sole();
        $this->assertSame('api', $entry->actor_type);
        $this->assertSame('Asked for it', $entry->meta['reason'] ?? null);
        $this->withToken($write)->postJson($anonymise, ['reason' => 'Again'])->assertNotFound();
        $this->withToken($read)->getJson('/api/v1/deletion-requests')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_statistics_and_dashboard_show_money_only_with_the_finance_scope(): void
    {
        $event = $this->event();
        $event->update(['bus_booked' => true, 'bus_seats' => 30]);
        $this->participation($event, $this->person('Ann', 'Ash', 9));
        $this->participation($event, $this->person('Ben', 'Birch', 12));
        $normal = $this->token(['events:read']);
        $this->withToken($normal)->getJson('/api/v1/statistics?groups=0')->assertUnprocessable()->assertJsonValidationErrors('groups');
        $this->withToken($normal)->getJson('/api/v1/statistics?event=unknown')->assertNotFound();
        $stats = $this->withToken($normal)->getJson('/api/v1/statistics?groups=2')->assertOk()->assertJsonPath('data.event', $event->public_id)
            ->assertJsonPath('data.costs', null)->assertJsonPath('data.discounts', null)->assertJsonCount(2, 'data.age_groups')->json('data');
        $this->assertSame(2, array_sum($stats['ages']));
        $this->withToken($this->token(['events:read', 'finance:read']))->getJson("/api/v1/statistics?event={$event->public_id}")->assertOk()->assertJsonPath('data.costs.participants', 2);

        $dashboard = $this->withToken($normal)->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.events.0.id', $event->public_id)
            ->assertJsonPath('data.events.0.registrations', 2)->assertJsonPath('data.events.0.bus.riders', 2)->assertJsonPath('data.events.0.bus.free', 28)->json('data');
        $this->assertArrayNotHasKey('finance', $dashboard);
        $this->assertArrayNotHasKey('finance', $dashboard['events'][0]);
        $this->assertArrayNotHasKey('unmatched_payments', $dashboard['todos']);
        $this->assertArrayNotHasKey('automations', $dashboard);
        $this->assertArrayHasKey('approvals', $dashboard['todos']);
        $this->assertArrayHasKey('safety_sheets', $dashboard);
        $money = $this->withToken($this->token(['events:read', 'finance:read', 'automations:read']))->getJson('/api/v1/dashboard')->assertOk()->json('data');
        $this->assertArrayHasKey('open', $money['finance']);
        $this->assertArrayHasKey('finance', $money['events'][0]);
        $this->assertArrayHasKey('unmatched_payments', $money['todos']);
        $this->assertArrayHasKey('upcoming', $money['automations']);
        $this->withToken($this->token(['finance:read']))->getJson('/api/v1/dashboard')->assertForbidden();
    }

    public function test_own_automations_can_be_created_and_deleted_but_standard_ones_stay(): void
    {
        $token = $this->token(['automations:read', 'automations:write']);
        $own = [
            'name' => ['de' => 'Dank an das Team', 'en' => 'Thanks to the team'], 'trigger' => 'after_event', 'trigger_config' => ['days' => 2],
            'config' => ['target' => ['source' => 'event', 'roles' => ['staff']], 'steps' => [['type' => 'notify_team', 'text' => 'Say thank you']]],
        ];
        $this->withToken($token)->postJson('/api/v1/automations', ['name' => $own['name']])->assertUnprocessable()->assertJsonValidationErrors(['trigger', 'config']);
        $this->withToken($token)->postJson('/api/v1/automations', ['active' => true] + $own)->assertUnprocessable()->assertJsonValidationErrors('active');
        $this->withToken($token)->postJson('/api/v1/automations', ['action' => 'invoice_run'] + $own)->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/automations', ['config' => ['target' => ['source' => 'subject'], 'steps' => [['type' => 'notify_team', 'text' => 'x']]]] + $own)->assertUnprocessable()->assertJsonValidationErrors('config.target.source');
        $this->withToken($token)->postJson('/api/v1/automations', ['config' => ['target' => ['source' => 'event'], 'steps' => [['type' => 'invoice', 'description' => 'Fee', 'amount' => 5]]]] + $own)->assertUnprocessable(); // needs finance:write
        $created = $this->withToken($token)->postJson('/api/v1/automations', $own)->assertCreated()->assertJsonPath('data.action', 'custom')->assertJsonPath('data.standard', false)
            ->assertJsonPath('data.active', false)->assertJsonPath('data.mode', 'confirm')->assertJsonPath('data.trigger_config.days', 2)->json('data');
        $this->assertStringStartsWith('own_', $created['key']);
        $this->withToken($token)->patchJson("/api/v1/automations/{$created['key']}", ['active' => true])->assertOk()->assertJsonPath('data.active', true);

        $this->withToken($token)->deleteJson('/api/v1/automations/deposit_invoices')->assertForbidden();
        $this->assertTrue(Automation::query()->where('key', 'deposit_invoices')->exists());
        $this->withToken($token)->deleteJson("/api/v1/automations/{$created['key']}")->assertOk();
        $this->assertFalse(Automation::query()->where('key', $created['key'])->exists());
        $this->withToken($token)->deleteJson("/api/v1/automations/{$created['key']}")->assertNotFound();
        $this->assertSame(0, Automation::query()->where('is_standard', false)->count());
    }

    public function test_webhooks_and_the_signing_secret_is_returned_only_once(): void
    {
        $token = $this->token(['settings:write']);
        $hook = ['name' => 'Website', 'url' => 'https://hooks.example.org/kasmin', 'events' => ['person.created', 'invoice.paid']];
        $this->withToken($token)->postJson('/api/v1/webhooks', ['url' => 'http://hooks.example.org/kasmin'] + $hook)->assertUnprocessable()->assertJsonValidationErrors('url');
        $this->withToken($token)->postJson('/api/v1/webhooks', ['events' => ['person.exploded']] + $hook)->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/webhooks', ['secret' => 'my-own-secret'] + $hook)->assertUnprocessable()->assertJsonValidationErrors('secret');
        $created = $this->withToken($token)->postJson('/api/v1/webhooks', $hook)->assertCreated()->assertJsonPath('data.active', true)->json('data');
        $secret = $created['secret'];
        $this->assertSame(48, strlen($secret));
        $model = Webhook::query()->whereKey((int) $created['id'])->firstOrFail();
        $this->assertSame($secret, $model->secret);
        $this->assertStringNotContainsString($secret, (string) DB::table('webhooks')->value('secret'), 'stored encrypted');

        $id = $created['id'];
        foreach ([$this->withToken($token)->getJson('/api/v1/webhooks')->assertOk()->assertJsonCount(1, 'data'),
            $this->withToken($token)->patchJson("/api/v1/webhooks/$id", ['active' => false, 'events' => ['person.created']])->assertOk()->assertJsonPath('data.active', false)->assertJsonPath('data.events', ['person.created'])] as $answer) {
            $this->assertStringNotContainsString($secret, (string) $answer->getContent());
            $this->assertStringNotContainsString('secret', (string) $answer->getContent());
        }
        $this->assertSame($secret, $model->refresh()->secret);
        $new = $this->withToken($token)->postJson("/api/v1/webhooks/$id/secret")->assertOk()->json('data.secret');
        $this->assertNotSame($secret, $new);
        $this->assertSame($new, $model->refresh()->secret);
        $this->assertStringNotContainsString($new, (string) $this->withToken($token)->getJson('/api/v1/webhooks')->getContent());
        $this->assertStringNotContainsString($secret, (string) AuditEvent::query()->get()->toJson());
        $this->assertStringNotContainsString($new, (string) AuditEvent::query()->get()->toJson());

        $this->withToken($token)->deleteJson("/api/v1/webhooks/$id")->assertOk();
        $this->withToken($token)->deleteJson("/api/v1/webhooks/$id")->assertNotFound();
    }

    /** The parity documentation cannot rot: a route without a line in docs/admin/api.md fails here. */
    public function test_every_api_route_is_named_in_the_documentation(): void
    {
        $docs = (string) file_get_contents(base_path('docs/admin/api.md'));
        $checked = 0;
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            // "/people/{person}/roles" is documented as `/people/{id}/roles`: any name may stand in the braces.
            $path = '/'.substr($route->uri(), strlen('api/v1/'));
            $pattern = '#`[^`\n]*'.str_replace('\{x\}', '\{[^}`/]+\}', preg_quote((string) preg_replace('/\{[^}]+\}/', '{x}', $path), '#')).'(?![A-Za-z0-9/{-])[^`\n]*`#';
            $this->assertSame(1, preg_match($pattern, $docs), "{$path} (routes/api.php) is missing in docs/admin/api.md");
            $checked++;
        }
        $this->assertGreaterThan(140, $checked);
        foreach (ApiToken::SCOPES as $scope) {
            $this->assertStringContainsString('`'.$scope.'`', $docs, "The scope {$scope} is missing in docs/admin/api.md");
        }
        $this->assertStringContainsString('| Area | Browser | REST | MCP |', $docs);
    }
}
