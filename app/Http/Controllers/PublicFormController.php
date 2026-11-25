<?php

namespace App\Http\Controllers;

use App\Models\Form;
use App\Models\PublicForm;
use App\Support\Auth\ProofOfWork;
use App\Support\Events\Guests;
use App\Support\Events\PublicForms;
use App\Support\Events\RegistrationException;
use App\Support\Forms\RegistrationAnswers;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Public forms, confirmation of the address, and personal guest invitations (PF01–PF04, PA05). Whether an address is
 * already known is never revealed.
 */
class PublicFormController extends Controller
{
    public function __construct(private readonly PublicForms $forms, private readonly Guests $guests, private readonly RegistrationAnswers $answers) {}

    public function show(string $slug): View
    {
        return $this->page($this->form($slug));
    }

    private function page(PublicForm $form, bool $sent = false): View
    {
        return view('public.form', [
            'sent' => $sent || session('sent') === true,
            'title' => $form->titleText(), 'intro' => $form->introText(), 'state' => $form->state(), 'form' => $form,
            'fields' => $this->forms->questions($form)->fields ?? collect(), 'action' => route('public.form.store', [$form->slug] + (request()->boolean('embed') ? ['embed' => 1] : [])),
            'askNewsletter' => $form->ask_newsletter && $form->kind !== 'interest' && Settings::bool('newsletter_ask'),
            'askEmail' => true, 'askBirthDate' => $form->kind !== 'interest', 'places' => $this->places($form),
        ]);
    }

    public function store(Request $request, string $slug): View|RedirectResponse
    {
        $form = $this->form($slug);
        // Bots fill every field: the same answer, but nothing is sent.
        if ((string) $request->input('website') === '') {
            abort_unless($form->state() === 'open', 403);
            try {
                $data = $this->data($request, $this->forms->questions($form), true, $form->kind !== 'interest');
                if (! ProofOfWork::valid($request->input('pow'))) {
                    throw ValidationException::withMessages(['pow' => __('public.check_failed')]);
                }
                if ($form->ask_newsletter && Settings::bool('newsletter_ask') && $request->boolean('newsletter')) {
                    $data['newsletter'] = (string) __('public.newsletter_consent');
                }
            } catch (ValidationException $e) {
                if ($request->boolean('embed')) { // no cookies in frames, so errors cannot wait for the next page
                    $request->flash();

                    return $this->page($form)->withErrors($e->validator);
                }

                throw $e;
            }
            $this->forms->submit($form, $data);
        }
        if ($request->boolean('embed')) {
            return $this->page($form, true);
        }

        return redirect()->route('public.form', $form->slug)->with('sent', true);
    }

    public function confirmation(string $token): View
    {
        $submission = $this->forms->find($token);

        return view('public.confirm', ['token' => $token, 'form' => $submission?->publicForm]);
    }

    public function confirm(string $token): View
    {
        $submission = $this->forms->find($token);
        if ($submission === null) {
            return view('public.confirm', ['token' => $token, 'form' => null]);
        }
        $result = $this->forms->confirm($submission);

        return view('public.done', ['status' => $result->status, 'note' => $result->note, 'title' => $result->publicForm?->titleText()]);
    }

    public function guest(string $token): View
    {
        $invitation = $this->guests->find($token);
        $event = $invitation?->participation?->event;
        if ($event === null) {
            return view('public.confirm', ['token' => $token, 'form' => null]);
        }

        return view('public.form', [
            'title' => $event->title, 'intro' => __('public.guest_intro', ['host' => $invitation->participation->person?->fullName()]), 'state' => 'open', 'form' => null,
            'fields' => $this->answers->form($event, Guests::ROLE)->fields ?? collect(), 'action' => route('public.guest.store', $token),
            'askNewsletter' => false, 'askEmail' => false, 'askBirthDate' => true, 'places' => null, 'sent' => false,
        ]);
    }

    public function acceptGuest(Request $request, string $token): View|RedirectResponse
    {
        $invitation = $this->guests->find($token);
        $event = $invitation?->participation?->event;
        if ($event === null) {
            return view('public.confirm', ['token' => $token, 'form' => null]);
        }
        try {
            $this->guests->accept($invitation, $this->data($request, $this->answers->form($event, Guests::ROLE), false, true));
        } catch (RegistrationException $e) {
            return back()->withInput()->withErrors(['event' => $e->getMessage()]);
        }

        return view('public.done', ['status' => 'done', 'note' => null, 'title' => $event->title]);
    }

    /** @return array<string, mixed> */
    private function data(Request $request, ?Form $questions, bool $email, bool $birthDate): array
    {
        return $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'locale' => ['required', 'in:de,en'],
            'privacy' => ['accepted'],
        ] + ($email ? ['email' => ['required', 'email', 'max:254']] : [])
          + ($birthDate ? ['birth_date' => ['required', 'date', 'before:today', 'after:1900-01-01']] : [])
          + ($questions !== null ? $this->answers->rules($questions) : []));
    }

    private function form(string $slug): PublicForm
    {
        return PublicForm::query()->where('slug', $slug)->where('active', true)->with('event.roles')->firstOrFail();
    }

    private function places(PublicForm $form): ?string
    {
        $role = $form->event?->roles->firstWhere('role_key', $form->role_key);
        if ($role === null) {
            return null;
        }
        $left = $role->directLeft();

        return (string) ($left === null || $left > 0 ? __('events.places_left', ['count' => $left ?? '∞']) : __('events.waitlist_only'));
    }
}
