<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventInstallment;
use App\Models\Form;
use App\Models\FormField;
use App\Models\Household;
use App\Models\Participation;
use App\Models\Person;
use App\Models\User;
use App\Support\Auth\PortalPerson;
use App\Support\Events\Guests;
use App\Support\Events\RegistrationException;
use App\Support\Events\Registrations;
use App\Support\Finance\Billing;
use App\Support\Finance\CancellationTerms;
use App\Support\Finance\Corrections;
use App\Support\Forms\Forms;
use App\Support\Forms\RegistrationAnswers;
use App\Support\Mail\Notifier;
use App\Support\People\Duplicates;
use App\Support\People\Guardians;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Legal guardians add children and register them for events (RG04, RG05, GP20, GP21).
 */
class PortalChildController extends Controller
{
    public function __construct(private readonly Registrations $registrations, private readonly Forms $forms, private readonly RegistrationAnswers $answers) {}

    public function create(): View
    {
        return view('portal.child-create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'birth_date' => ['required', 'date', 'before:today', 'after:'.now()->subYears(25)->toDateString()],
        ]);
        $guardian = $this->guardian($request);
        $household = $this->household($guardian);

        $child = Person::query()->create($data + ['locale' => $guardian->locale]);
        $child->guardians()->attach($guardian->id);
        $household->members()->attach($child->id, ['role' => 'child']);
        foreach ($household->guardians()->where('people.id', '!=', $guardian->id)->get() as $other) {
            $child->guardians()->attach($other->id);
        }

        if (app(Duplicates::class)->of($child) !== []) {
            app(Notifier::class)->event('registrations', __('portal.notify_duplicate', ['name' => $child->fullName()]), url('/admin/duplicates')); // reviewed by administrators (RG04)
        }

        return redirect()->route('portal.child', $child->public_id)->with('status', __('portal.child_added'));
    }

    public function show(Request $request, string $publicId): View
    {
        $child = $this->child($request, $publicId);
        $viewer = $this->guardian($request);
        $participations = $child->participations()->with('event')->latest('registered_at')->get()->filter(fn (Participation $p) => $p->visibleInPortal($viewer))->values();

        return view('portal.child', [
            'child' => $child,
            'answerForms' => $participations->filter(fn (Participation $p) => $p->event !== null && $this->answers->form($p->event, $p->role_key) !== null)->keyBy('id')->all(),
            'participations' => $participations,
            'open' => $this->registrations->openFor($child, $this->by($request, $child)),
            'self' => $child->is($this->guardian($request)),
            'mayHaveLogin' => ! $child->is($this->guardian($request)) && app(Guardians::class)->mayHaveLogin($child),
            'guestsLeft' => $participations->mapWithKeys(fn (Participation $p) => [$p->id => app(Guests::class)->left($p)])->all(),
            'registeredIds' => $participations->whereNotIn('status', ['cancelled', 'declined'])->pluck('event_id')->all(),
            'forms' => Form::query()->where('kind', 'person')->where('active', true)->orderBy('sort')->get()
                ->map(fn (Form $form) => ['form' => $form, 'status' => $this->forms->status($form, $child)])
                ->reject(fn (array $item) => $item['status'] === 'not_applicable' || ($child->is($viewer) && ($item['form']->family_emergency_contacts ? $item['status'] === 'missing' : false)))->values(),
        ]);
    }

    public function register(Request $request, string $publicId): RedirectResponse
    {
        $child = $this->child($request, $publicId);
        $event = Event::query()->where('public_id', (string) $request->input('event'))->firstOrFail();
        $role = (string) $request->input('role', 'child');
        abort_unless($event->roles()->where('role_key', $role)->where('registered_by', $this->by($request, $child))->exists(), 404);
        $form = $this->answers->form($event, $role);
        $values = [];
        if ($form !== null) {
            $values = (array) ($request->validate($this->answers->rules($form, $this->questions($event, $form, $child)))['values'] ?? []);
        }
        try {
            $participation = DB::transaction(function () use ($event, $child, $role, $request, $form, $values) {
                $participation = $this->registrations->register($event, $child, $role, $this->guardian($request));
                if ($form !== null) {
                    $this->answers->save($participation, $form, $values, $this->guardian($request));
                }

                return $participation;
            });
        } catch (RegistrationException $e) {
            return back()->withErrors(['event' => $e->getMessage()]);
        }

        return redirect()->route('portal.child', $child->public_id)->with('status', __('portal.registered_'.($participation->status === 'waitlist' ? 'waitlist' : 'ok')));
    }

    /** Conditions and questions before registering (RG05, FB01). */
    public function registerForm(Request $request, string $publicId, string $event, string $role): View
    {
        $child = $this->child($request, $publicId);
        $model = Event::query()->where('public_id', $event)->where('status', 'open')->firstOrFail();
        $eventRole = $model->roles()->where('role_key', $role)->where('registered_by', $this->by($request, $child))->firstOrFail();
        $form = $this->answers->form($model, $role);
        $billing = app(Billing::class);
        $draft = new Participation(['event_id' => $model->id, 'person_id' => $child->id, 'role_key' => $role, 'status' => 'registered', 'registered_at' => now()]);
        $draft->setRelation('event', $model)->setRelation('person', $child);
        $model->load('installments', 'roles', 'prices');

        return view('portal.register-event', [
            'child' => $child, 'event' => $model, 'role' => $eventRole, 'participation' => null, 'editable' => true, 'left' => $eventRole->directLeft(),
            'fields' => $form !== null ? $this->questions($model, $form, $child) : collect(),
            'values' => old('values', []), 'price' => $billing->price($model, $draft),
            'installments' => $model->installments->map(fn (EventInstallment $i) => ['label' => $i->labelText(), 'amount' => $billing->base($model, $i, $draft)])->filter(fn (array $i) => $i['amount'] > 0)->all(),
            'terms' => CancellationTerms::for($draft),
        ]);
    }

    public function editAnswers(Request $request, string $publicId, int $participation): View
    {
        $child = $this->child($request, $publicId);
        [$model, $form] = $this->answered($request, $child, $participation);

        return view('portal.register-event', [
            'child' => $child, 'event' => $model->event, 'role' => $model->event?->roles()->where('role_key', $model->role_key)->first(), 'participation' => $model,
            'editable' => $model->event !== null && $this->answers->editable($model->event), 'left' => null,
            'fields' => $form->fields, 'values' => old('values', $this->answers->values($model, $form)), 'price' => 0, 'installments' => [], 'terms' => [],
        ]);
    }

    public function saveAnswers(Request $request, string $publicId, int $participation): RedirectResponse
    {
        $child = $this->child($request, $publicId);
        [$model, $form] = $this->answered($request, $child, $participation);
        abort_unless($model->event !== null && $this->answers->editable($model->event), 403);
        $values = (array) ($request->validate($this->answers->rules($form))['values'] ?? []);
        $this->answers->save($model, $form, $values, $this->guardian($request));

        return redirect()->route('portal.child', $child->public_id)->with('status', __('portal.answers_saved'));
    }

    /** @return array{0: Participation, 1: Form} */
    private function answered(Request $request, Person $child, int $id): array
    {
        $participation = $this->participation($request, $child, $id);
        abort_if(in_array($participation->status, ['cancelled', 'declined'], true), 404);
        $form = $participation->event !== null ? $this->answers->form($participation->event, $participation->role_key) : null;
        abort_if($form === null, 404);

        return [$participation, $form];
    }

    /** @return Collection<int, FormField> questions to ask now; those of the family only once */
    private function questions(Event $event, Form $form, Person $child): Collection
    {
        return $this->answers->asksFamily($event, $form, $child) ? collect($form->fields->all()) : collect($form->fields->where('scope', '!=', 'family')->all());
    }

    public function cancel(Request $request, string $publicId, int $participation): RedirectResponse
    {
        $child = $this->child($request, $publicId);
        $model = $this->participation($request, $child, $participation);
        $this->registrations->cancel($model);
        if ($model->event !== null) {
            app(Corrections::class)->refreshDrafts($model->event);
        }

        return back()->with('status', __('portal.cancelled'));
    }

    /** Invites a guest to the own registration (PA05). */
    public function inviteGuest(Request $request, string $publicId, int $participation): RedirectResponse
    {
        $child = $this->child($request, $publicId);
        abort_unless($child->is($this->guardian($request)) && ($child->ageAt() ?? 18) >= 18, 404);
        $model = $this->participation($request, $child, $participation);
        $email = (string) $request->validate(['email' => ['required', 'email', 'max:254']])['email'];
        try {
            app(Guests::class)->invite($model, $email, $child->locale);
        } catch (RegistrationException $e) {
            return back()->withErrors(['email' => $e->getMessage()]);
        }

        return back()->with('status', __('public.invited'));
    }

    /** Accept or decline an offered place from the waitlist (A05). */
    public function answerOffer(Request $request, string $publicId, int $participation): RedirectResponse
    {
        $child = $this->child($request, $publicId);
        $model = $this->participation($request, $child, $participation);
        $accept = $request->validate(['answer' => ['required', 'in:accept,decline']])['answer'] === 'accept';
        try {
            $this->registrations->answerOffer($model, $accept);
        } catch (RegistrationException $e) {
            return back()->withErrors(['offer' => $e->getMessage()]);
        }

        return back()->with('status', __($accept ? 'portal.offer_accepted' : 'portal.offer_declined'));
    }

    /** Who registers: the person itself or a legal guardian (EE05). */
    private function by(Request $request, Person $child): string
    {
        return $child->is($this->guardian($request)) ? 'self' : 'guardian';
    }

    /** A registration of this person that the viewer may see (PE05a). */
    private function participation(Request $request, Person $child, int $id): Participation
    {
        $participation = Participation::query()->where('person_id', $child->id)->with('event', 'person')->findOrFail($id);
        abort_unless($participation->visibleInPortal($this->guardian($request)), 404);

        return $participation;
    }

    private function guardian(Request $request): Person
    {
        /** @var User $user */
        $user = $request->user();
        $person = PortalPerson::for($request);
        abort_if($person === null, 403);

        return $person;
    }

    private function household(Person $guardian): Household
    {
        $household = $guardian->households()->wherePivot('role', 'guardian')->first();
        if ($household === null) {
            $household = Household::query()->create(['name' => $guardian->last_name, 'locale' => $guardian->locale]);
            $household->members()->attach($guardian->id, ['role' => 'guardian']);
        }

        return $household;
    }

    /** Only children of the logged-in legal guardian, and the person itself, are reachable. */
    private function child(Request $request, string $publicId): Person
    {
        $person = $this->guardian($request);

        return $person->public_id === $publicId ? $person : $person->wards()->where('public_id', $publicId)->firstOrFail();
    }
}
