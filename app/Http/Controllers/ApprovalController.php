<?php

namespace App\Http\Controllers;

use App\Support\Audit\Audit;
use App\Support\Automations\Approvals;
use App\Support\Automations\Engine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use PragmaRX\Google2FA\Google2FA;

/**
 * Decision page of an approval link (AT03, AT04): GET only shows the plan; the decision needs a button press (POST),
 * and approvers with a second factor confirm it with their code (step-up).
 */
class ApprovalController extends Controller
{
    public function __construct(private readonly Approvals $approvals, private readonly Engine $engine) {}

    public function show(Request $request, string $token): View
    {
        $model = $this->approvals->findByToken($token);
        if ($model?->user?->person !== null) {
            app()->setLocale($model->user->person->locale);
        }

        return view('approvals.show', [
            'model' => $model,
            'run' => $model?->run,
            'token' => $token,
            'decision' => $request->query('decision') === 'deny' ? 'deny' : 'approve',
            'needsCode' => $model !== null && $model->user?->two_factor_confirmed_at !== null,
        ]);
    }

    public function decide(Request $request, string $token): RedirectResponse
    {
        $model = $this->approvals->findByToken($token);
        if ($model === null || $model->run === null || $model->user === null) {
            Audit::record('automation.approval_link_invalid', null, null, null, 'system');

            return redirect()->route('approvals.show', $token);
        }
        $data = $request->validate([
            'decision' => ['required', 'in:approve,deny'],
            'excluded' => ['array'], 'excluded.*' => ['string', 'max:120'],
            'comment' => ['nullable', 'string', 'max:490'],
            'code' => ['nullable', 'string', 'max:10'],
        ]);
        $user = $model->user;
        if ($user->two_factor_confirmed_at !== null
            && ! (new Google2FA)->verifyKey((string) $user->two_factor_secret, preg_replace('/\s+/', '', (string) ($data['code'] ?? '')), 1)) {
            return back()->withErrors(['code' => __('automations.approval.code_invalid')])->withInput();
        }
        try {
            $run = $this->engine->decide($model->run, $user, $data['decision'] === 'approve', array_values($data['excluded'] ?? []), $data['comment'] ?? null);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['decision' => $e->getMessage()]);
        }

        return redirect()->route('approvals.done')->with('status', __('automations.approval.done_'.$run->status));
    }

    public function done(Request $request): View
    {
        return view('approvals.done', ['status' => (string) $request->session()->get('status', '')]);
    }
}
