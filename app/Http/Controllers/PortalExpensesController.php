<?php

namespace App\Http\Controllers;

use App\Models\Advance;
use App\Models\CostCenter;
use App\Models\Event;
use App\Models\ExpenseClaim;
use App\Models\Person;
use App\Models\User;
use App\Support\Accounting\Accounting;
use App\Support\Accounting\AccountingException;
use App\Support\Accounting\ExpenseClaims;
use App\Support\Auth\PortalPerson;
use App\Support\Finance\Iban;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Expense claims in the portal (E1d.3): team members submit their receipts (photo or PDF from the phone), see the
 * status of their own claims and advances and keep the IBAN for refunds. Nobody sees the claims of others, and not
 * in the preview of somebody else's portal either.
 */
class PortalExpensesController extends Controller
{
    public function __construct(private readonly ExpenseClaims $claims) {}

    /** Team members (an active role) and everybody who already has claims or advances. */
    public static function mayUse(Person $person): bool
    {
        return $person->activeRoleKeys() !== [] || ExpenseClaim::query()->where('person_id', $person->id)->exists()
            || Advance::query()->where('person_id', $person->id)->exists();
    }

    public function index(Request $request): View
    {
        $person = $this->person($request);

        return view('portal.expenses', [
            'person' => $person,
            'iban' => Iban::mask($person->payout_iban),
            'claims' => ExpenseClaim::query()->where('person_id', $person->id)->with('event')->latest('date')->latest('id')->limit(100)->get(),
            'advances' => Advance::query()->where('person_id', $person->id)->latest('paid_on')->limit(20)->get(),
            'openAdvances' => Advance::query()->where('person_id', $person->id)->whereNull('settled_on')->orderBy('paid_on')->get(),
            'accounts' => ExpenseClaims::expenseAccounts(),
            'defaultAccount' => ExpenseClaims::defaultAccount()?->id,
            'flatRates' => ExpenseClaims::flatRates(),
            'events' => self::events(),
            'costCenters' => Accounting::costCenters() ? CostCenter::query()->orderBy('sort')->orderBy('name')->pluck('name', 'id') : collect(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $person = $this->person($request);
        $flat = $request->input('kind') !== null && $request->input('kind') !== 'receipt';
        $data = $request->validate([
            'kind' => ['nullable', 'string', 'max:80'],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => [$flat ? 'nullable' : 'required', 'numeric', 'min:0.01', 'max:100000'],
            'quantity' => ['nullable', 'numeric', 'min:0.01', 'max:100000'],
            'purpose' => [$flat ? 'nullable' : 'required', 'string', 'max:250'],
            'account_id' => ['nullable', 'integer'],
            'event_id' => ['nullable', 'integer', Rule::in(self::events()->keys()->all())],
            'cost_center_id' => ['nullable', 'integer'],
            'advance_id' => ['nullable', 'integer'],
            'waived' => ['nullable', 'boolean'],
            'receipt' => [$flat ? 'nullable' : 'required', 'file', 'max:'.ExpenseClaims::RECEIPT_MAX_KB, 'mimetypes:'.implode(',', ExpenseClaims::RECEIPT_TYPES)],
        ], ['receipt.required' => __('accounting.expenses.portal.receipt_needed')]);
        /** @var User $user */
        $user = $request->user();
        try {
            $this->claims->submit($person, [
                'date' => (string) $data['date'], 'amount_cents' => $flat ? null : (int) round(((float) $data['amount']) * 100), 'purpose' => $data['purpose'] ?? null,
                'flat_rate' => $flat ? (string) $data['kind'] : null, 'quantity' => $data['quantity'] ?? null, 'account_id' => $data['account_id'] ?? null,
                'event_id' => $data['event_id'] ?? null, 'cost_center_id' => $data['cost_center_id'] ?? null, 'advance_id' => $data['advance_id'] ?? null,
                'waived' => (bool) ($data['waived'] ?? false), 'receipt' => $request->file('receipt'),
            ], $user);
        } catch (AccountingException $e) {
            return back()->withInput()->withErrors(['claim' => $e->getMessage()]);
        }

        return redirect()->route('portal.expenses')->with('status', __('accounting.expenses.portal.submitted'));
    }

    public function saveIban(Request $request): RedirectResponse
    {
        $person = $this->person($request);
        $iban = (string) $request->validate(['iban' => ['nullable', 'string', 'max:42']])['iban'];
        try {
            $this->claims->setIban($person, $iban);
        } catch (AccountingException $e) {
            return back()->withErrors(['iban' => $e->getMessage()]);
        }

        return redirect()->route('portal.expenses')->with('status', __($iban === '' ? 'accounting.expenses.portal.iban_removed' : 'accounting.expenses.portal.iban_saved'));
    }

    public function waive(Request $request, int $claim): RedirectResponse
    {
        $model = ExpenseClaim::query()->where('person_id', $this->person($request)->id)->findOrFail($claim);
        /** @var User $user */
        $user = $request->user();
        try {
            $this->claims->waive($model, $user);
        } catch (AccountingException $e) {
            return back()->withErrors(['claim' => $e->getMessage()]);
        }

        return redirect()->route('portal.expenses')->with('status', __('accounting.expenses.portal.waived'));
    }

    public function receipt(Request $request, int $claim): StreamedResponse
    {
        $model = ExpenseClaim::query()->where('person_id', $this->person($request)->id)->findOrFail($claim);

        return self::download($model);
    }

    /** The receipt of a claim, never cached and never shown as a page of the site. */
    public static function download(ExpenseClaim $claim): StreamedResponse
    {
        $path = (string) $claim->receipt_path;
        abort_unless($path !== '' && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, 'receipt-'.$claim->id.'.'.pathinfo($path, PATHINFO_EXTENSION), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** @return Collection<int, string> events of the last half year and the coming one, by id */
    public static function events(): Collection
    {
        return Event::query()->where('is_template', false)->whereNotIn('status', ['draft', 'cancelled'])
            ->where('ends_at', '>=', now()->subMonths(6))->where('starts_at', '<=', now()->addYear())->orderByDesc('starts_at')->pluck('title', 'id');
    }

    /** The logged-in person only: not in the preview of somebody else's portal, and only for team members. */
    private function person(Request $request): Person
    {
        /** @var User $user */
        $user = $request->user();
        $person = PortalPerson::for($request);
        abort_if($person === null || $person->id !== $user->person_id || ! self::mayUse($person), 404);

        return $person;
    }
}
