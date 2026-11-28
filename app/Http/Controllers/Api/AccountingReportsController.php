<?php

namespace App\Http\Controllers\Api;

use App\Models\Account;
use App\Models\CostCenter;
use App\Models\Period;
use App\Support\Accounting\Reports;
use App\Support\Audit\Audit;
use App\Support\Lists\ListExport;
use App\Support\Lists\ListTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reports of the accounting (E1), as on the page Accounting → Reports: balance sheet, income statement, cost centres,
 * statement of funds, ledger of an account and open items. Scope: finance:read.
 */
class AccountingReportsController extends AccountingController
{
    /**
     * One report as a table (title, headers, rows; amounts as text in the currency of the installation), or with
     * format csv, xlsx or pdf as the file of Export on the page (logged). report: balance (date), income (period,
     * cost_center), cost_centers (period), funds (period), ledger (account, from, to), open_items (date). The date is
     * today and the period the current one unless given.
     */
    public function show(Request $request, string $report): JsonResponse|Response
    {
        $this->scope('finance:read');
        abort_unless(array_key_exists($report, (array) __('accounting.reports.kinds')), 404);
        $data = $request->validate([
            'date' => ['sometimes', 'nullable', 'date'], 'period' => ['sometimes', 'nullable'], 'cost_center' => ['sometimes', 'nullable'], 'account' => [$report === 'ledger' ? 'required' : 'sometimes', 'nullable'],
            'from' => ['sometimes', 'nullable', 'date'], 'to' => ['sometimes', 'nullable', 'date'], 'format' => ['sometimes', 'nullable', Rule::in(['json', ...ListExport::FORMATS])],
        ]);
        $table = $this->table($report, $data);
        $format = (string) ($data['format'] ?? 'json');
        if ($format === 'json') {
            return response()->json(['data' => ['report' => $report, 'title' => $table->title, 'headers' => $table->headers, 'rows' => $table->rows, 'confidential' => $table->confidential]]);
        }
        Audit::record('accounting.report_exported', null, null, ['report' => $report, 'format' => $format]);

        return response(app(ListExport::class)->render($table, $format), 200, [
            'Content-Type' => ListExport::TYPES[$format],
            'Content-Disposition' => ($format === 'pdf' ? 'inline' : 'attachment').'; filename="'.ListExport::fileName(str($table->title)->ascii()->toString(), $format).'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @param  array<string, mixed>  $data */
    private function table(string $report, array $data): ListTable
    {
        $reports = app(Reports::class);
        $day = Carbon::parse($data['date'] ?? now());
        $period = fn (): Period => $this->findOptional(Period::class, $data['period'] ?? null, 'period') ?? $this->currentPeriod()
            ?? throw ValidationException::withMessages(['period' => ['There is no period yet.']]);

        return match ($report) {
            'balance' => $reports->balanceSheet($day),
            'income' => $reports->incomeStatement($period(), $this->findOptional(CostCenter::class, $data['cost_center'] ?? null, 'cost_center')),
            'cost_centers' => $reports->costCenters($period()),
            'funds' => $reports->funds($period()),
            'ledger' => $reports->ledger($this->find(Account::class, $data['account'], 'account'),
                Carbon::parse($data['from'] ?? $this->currentPeriod()->starts_on ?? now()->startOfYear()), Carbon::parse($data['to'] ?? now())),
            default => $reports->openItems($day),
        };
    }
}
