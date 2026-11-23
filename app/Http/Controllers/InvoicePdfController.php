<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Finance\InvoicePdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Invoice PDFs: for finance roles in the admin interface and for the paying family in the portal (IN04, PO03).
 */
class InvoicePdfController extends Controller
{
    public function admin(Request $request, Invoice $invoice): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canViewFinance(), 403);

        return $this->pdf($invoice);
    }

    public function portal(Request $request, Invoice $invoice): Response
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($invoice->status !== 'draft' && self::visibleTo($invoice, $user), 404);
        Audit::record('invoice.downloaded', $invoice);

        return $this->pdf($invoice);
    }

    /** Payer, or a legal guardian of the invoice's family. */
    public static function visibleTo(Invoice $invoice, User $user): bool
    {
        $person = $user->person;
        if ($person === null) {
            return false;
        }

        return $invoice->payer_id === $person->id
            || ($invoice->household_id !== null && $person->households()->wherePivot('role', 'guardian')->whereKey($invoice->household_id)->exists());
    }

    private function pdf(Invoice $invoice): Response
    {
        $service = app(InvoicePdf::class);
        $paid = $invoice->status === 'paid';

        return response($service->render($invoice, $paid), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$service->fileName($invoice, $paid).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
