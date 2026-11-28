<?php

namespace App\Filament\Pages;

use App\Filament\Support\InArea;
use App\Models\Account;
use App\Models\CostCenter;
use App\Models\Period;
use App\Support\Accounting\Accounting;
use App\Support\Accounting\Reports;
use App\Support\Audit\Audit;
use App\Support\Auth\Permissions;
use App\Support\Lists\ListExport;
use App\Support\Lists\ListTable;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports of the accounting (E1): balance sheet, income statement, cost centres with budget, statement of funds,
 * ledger of an account, open items – shown and exported as Excel, CSV or PDF.
 */
class AccountingReportsPage extends Page
{
    use InArea;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $slug = 'accounting/reports';

    protected string $view = 'filament.accounting.reports';

    public string $report = 'balance';

    public ?int $period = null;

    public ?int $costCenter = null;

    public ?int $account = null;

    public ?string $day = null;

    public ?string $from = null;

    public ?string $to = null;

    public static function getNavigationLabel(): string
    {
        return __('accounting.reports.title');
    }

    public function getTitle(): string
    {
        return __('accounting.reports.title');
    }

    public static function canAccess(): bool
    {
        return Permissions::current('finance');
    }

    public function mount(): void
    {
        $this->report = (string) request()->query('report', 'balance');
        $this->account = request()->integer('account') ?: null;
        $current = Period::of(now()) ?? Period::query()->orderByDesc('ends_on')->first();
        $this->period = $current?->id;
        $this->day = now()->toDateString();
        $this->from = $current?->starts_on->toDateString() ?? now()->startOfYear()->toDateString();
        $this->to = now()->toDateString();
    }

    /** @return array<string, string> */
    public function reports(): array
    {
        $all = __('accounting.reports.kinds');

        return array_filter($all, fn (string $key) => match ($key) {
            'cost_centers' => Accounting::costCenters(),
            'funds' => Accounting::funds(),
            default => true,
        }, ARRAY_FILTER_USE_KEY);
    }

    public function table(): ?ListTable
    {
        $reports = app(Reports::class);
        $period = Period::query()->find($this->period);

        return match ($this->report) {
            'balance' => $reports->balanceSheet(Carbon::parse($this->day ?? now())),
            'income' => $period !== null ? $reports->incomeStatement($period, CostCenter::query()->find($this->costCenter)) : null,
            'cost_centers' => $period !== null ? $reports->costCenters($period) : null,
            'funds' => $period !== null ? $reports->funds($period) : null,
            'ledger' => ($account = Account::query()->find($this->account)) !== null ? $reports->ledger($account, Carbon::parse($this->from ?? now()), Carbon::parse($this->to ?? now())) : null,
            'open_items' => $reports->openItems(Carbon::parse($this->day ?? now())),
            default => null,
        };
    }

    public function export(string $format): ?StreamedResponse
    {
        $table = $this->table();
        if ($table === null || ! in_array($format, ListExport::FORMATS, true)) {
            return null;
        }
        Audit::record('accounting.report_exported', null, null, ['report' => $this->report, 'format' => $format]);
        $content = app(ListExport::class)->render($table, $format);

        return response()->streamDownload(fn () => print ($content), ListExport::fileName($table->title, $format), ['Content-Type' => ListExport::TYPES[$format]]);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            'table' => $this->table(),
            'periods' => Period::query()->orderByDesc('starts_on')->pluck('name', 'id')->all(),
            'centers' => Accounting::costCenters() ? CostCenter::query()->orderBy('name')->pluck('name', 'id')->all() : [],
            'accounts' => Account::query()->orderBy('sort')->get()->mapWithKeys(fn (Account $a) => [$a->id => $a->label()])->all(),
        ];
    }
}
