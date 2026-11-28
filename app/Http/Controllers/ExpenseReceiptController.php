<?php

namespace App\Http\Controllers;

use App\Models\ExpenseClaim;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The receipt of an expense claim for the finance roles of the admin interface (E1d.3). */
class ExpenseReceiptController extends Controller
{
    public function show(Request $request, int $claim): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canViewFinance(), 403);
        $model = ExpenseClaim::query()->findOrFail($claim);
        Audit::record('expense_claim.receipt_viewed', $model);

        return PortalExpensesController::download($model);
    }
}
