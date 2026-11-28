<?php

namespace App\Http\Controllers;

use App\Models\PublicForm;
use App\Support\People\SignUps;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public registration for legal guardians (RG01–RG03): since B16 a public form of the kind "signup"; the old address
 * /register forwards to it. Name and e-mail, then a link to confirm; the answer is the same whether or not the address
 * already exists, existing accounts simply receive a login link.
 */
class PublicRegistrationController extends Controller
{
    public function __construct(private readonly SignUps $signUps) {}

    /** The old address of the sign-up: forwards to the sign-up form, if one is switched on (B16). */
    public function show(): View|RedirectResponse
    {
        $form = PublicForm::signUp();

        return $form !== null ? redirect()->to($form->url()) : view('public.no-sign-up');
    }

    /** Sign-ups sent to the old address (e.g. a page opened before the update) go to the active form. */
    public function store(Request $request): RedirectResponse|View
    {
        $form = PublicForm::signUp();

        return $form !== null ? $this->signUps->fromRequest($form, $request) : view('public.no-sign-up');
    }

    public function confirmation(string $token): View
    {
        $signUp = $this->signUps->find($token);
        if ($signUp !== null && ! $this->signUps->stillOpen($signUp)) {
            return view('public.no-sign-up');
        }

        return $signUp === null
            ? view('public.confirm-simple', ['title' => __('public.invalid_title'), 'action' => null, 'text' => __('public.invalid')])
            : view('public.confirm-simple', ['title' => __('portal.register_title'), 'action' => route('register.confirm.store', $token), 'text' => null]);
    }

    public function confirm(string $token): View|RedirectResponse
    {
        $signUp = $this->signUps->find($token);
        if ($signUp === null || ! $this->signUps->stillOpen($signUp)) {
            return $this->confirmation($token);
        }

        return redirect()->route('login.link', $this->signUps->confirm($signUp));
    }
}
