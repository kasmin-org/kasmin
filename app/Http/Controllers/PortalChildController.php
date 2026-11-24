<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Form;
use App\Models\Household;
use App\Models\Participation;
use App\Models\Person;
use App\Models\User;
use App\Support\Auth\PortalPerson;
use App\Support\Events\RegistrationException;
use App\Support\Events\Registrations;
use App\Support\Forms\Forms;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Legal guardians add children and register them for events (RG04, RG05, GP20, GP21).
 */
class PortalChildController extends Controller
{
    public function __construct(private readonly Registrations $registrations, private readonly Forms $forms) {}

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

        return redirect()->route('portal.child', $child->public_id)->with('status', __('portal.child_added'));
    }

    public function show(Request $request, string $publicId): View
    {
        $child = $this->child($request, $publicId);
        $participations = $child->participations()->with('event')->latest('registered_at')->get();

        return view('portal.child', [
            'child' => $child,
            'participations' => $participations,
            'open' => $this->registrations->openFor($child),
            'registeredIds' => $participations->whereNotIn('status', ['cancelled', 'declined'])->pluck('event_id')->all(),
            'forms' => Form::query()->where('active', true)->orderBy('sort')->get()
                ->map(fn (Form $form) => ['form' => $form, 'status' => $this->forms->status($form, $child)])
                ->reject(fn (array $item) => $item['status'] === 'not_applicable')->values(),
        ]);
    }

    public function register(Request $request, string $publicId): RedirectResponse
    {
        $child = $this->child($request, $publicId);
        $event = Event::query()->where('public_id', (string) $request->input('event'))->firstOrFail();
        try {
            $participation = $this->registrations->register($event, $child, (string) $request->input('role', 'child'), $this->guardian($request));
        } catch (RegistrationException $e) {
            return back()->withErrors(['event' => $e->getMessage()]);
        }

        return back()->with('status', __('portal.registered_'.($participation->status === 'waitlist' ? 'waitlist' : 'ok')));
    }

    public function cancel(Request $request, string $publicId, int $participation): RedirectResponse
    {
        $child = $this->child($request, $publicId);
        $model = Participation::query()->where('person_id', $child->id)->findOrFail($participation);
        $this->registrations->cancel($model);

        return back()->with('status', __('portal.cancelled'));
    }

    /** Accept or decline an offered place from the waitlist (A05). */
    public function answerOffer(Request $request, string $publicId, int $participation): RedirectResponse
    {
        $child = $this->child($request, $publicId);
        $model = Participation::query()->where('person_id', $child->id)->with('event')->findOrFail($participation);
        $accept = $request->validate(['answer' => ['required', 'in:accept,decline']])['answer'] === 'accept';
        try {
            $this->registrations->answerOffer($model, $accept);
        } catch (RegistrationException $e) {
            return back()->withErrors(['offer' => $e->getMessage()]);
        }

        return back()->with('status', __($accept ? 'portal.offer_accepted' : 'portal.offer_declined'));
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

    /** Only children of the logged-in legal guardian are reachable. */
    private function child(Request $request, string $publicId): Person
    {
        return $this->guardian($request)->wards()->where('public_id', $publicId)->firstOrFail();
    }
}
