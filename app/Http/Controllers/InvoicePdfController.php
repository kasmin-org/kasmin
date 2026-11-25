<?php

namespace App\Http\Controllers;

use App\Models\ArchivedInvoice;
use App\Models\Invoice;
use App\Models\Person;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Auth\PortalPerson;
use App\Support\Finance\InvoicePdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
        abort_unless($invoice->status !== 'draft' && self::visibleTo($invoice, $user, PortalPerson::for($request)), 404);
        Audit::record('invoice.downloaded', $invoice);

        return $this->pdf($invoice);
    }

    /** PDF of an invoice from the archive (MI07), for finance roles. */
    public function archived(Request $request, ArchivedInvoice $archived): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canViewFinance(), 403);
        abort_unless($archived->pdf_path !== null && Storage::disk('local')->exists($archived->pdf_path), 404);
        Audit::record('invoice_archive.downloaded', null, null, ['number' => $archived->number]);

        return response((string) Storage::disk('local')->get($archived->pdf_path), 200, [
            'Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store',
            'Content-Disposition' => 'inline; filename="'.preg_replace('/[^A-Za-z0-9_.-]+/', '-', $archived->number).'.pdf"',
        ]);
    }

    /** Payer, or a legal guardian of the invoice's family. */
    public static function visibleTo(Invoice $invoice, User $user, ?Person $person = null): bool
    {
        $person ??= $user->person;
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
