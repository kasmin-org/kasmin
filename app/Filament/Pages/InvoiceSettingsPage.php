<?php

namespace App\Filament\Pages;

use App\Filament\Support\InArea;
use App\Filament\Support\SettingsPage;
use App\Models\User;
use App\Support\Accounting\ExpenseClaims;
use App\Support\Auth\Permissions;
use App\Support\Finance\Iban;
use App\Support\Settings;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * Settings of the invoices (part of S01): bank account for invoices and the EPC QR code, numbering, due dates, reminders and fees.
 */
class InvoiceSettingsPage extends SettingsPage
{
    use InArea;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCurrencyEuro;

    protected static ?string $slug = 'settings/invoices';

    public static function getNavigationLabel(): string
    {
        return __('admin.settings.invoices_page');
    }

    public function getTitle(): string
    {
        return __('admin.settings.invoices_page');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && (Permissions::allows($user, 'finance', 'edit') || Permissions::allows($user, 'settings', 'edit'));
    }

    protected static function keys(): array
    {
        return ['organisation_iban', 'organisation_bic', 'organisation_bank', 'invoice_number_format', 'invoice_next_number', 'invoice_due_days',
            'invoice_reminder_days_before', 'invoice_overdue_days', 'invoice_overdue_fees', 'expense_flat_rates'];
    }

    protected static function switches(): array
    {
        return ['invoice_number_per_year', 'invoice_attach_paid', 'expense_four_eyes'];
    }

    protected static function section(): string
    {
        return 'invoices';
    }

    protected function sections(): array
    {
        $hint = fn (string $key) => self::hint($key);

        return [
            Section::make(__('admin.settings.bank'))->columns(3)->schema([
                TextInput::make('organisation_iban')->label('IBAN')->maxLength(42)
                    ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail) {
                        if ($value !== null && $value !== '' && ! self::validIban((string) $value)) {
                            $fail(__('admin.settings.iban_invalid'));
                        }
                    }),
                TextInput::make('organisation_bic')->label('BIC')->maxLength(11)->hintIcon(...$hint('bic')),
                TextInput::make('organisation_bank')->label(__('admin.settings.bank_name')),
            ]),
            Section::make(__('admin.settings.invoices'))->columns(3)->schema([
                TextInput::make('invoice_number_format')->label(__('admin.settings.number_format'))->required()
                    ->rule(fn (Get $get) => function (string $attribute, mixed $value, \Closure $fail) use ($get) {
                        if (! str_contains((string) $value, '{number}') || ($get('invoice_number_per_year') && ! str_contains((string) $value, '{year}'))) {
                            $fail(__('admin.settings.number_format_invalid'));
                        }
                    })->hintIcon(...$hint('number_format')),
                TextInput::make('invoice_next_number')->label(__('admin.settings.next_number'))->numeric()->minValue(1)->required()->hintIcon(...$hint('next_number')),
                Toggle::make('invoice_number_per_year')->label(__('admin.settings.number_per_year'))->inline(false)->hintIcon(...$hint('number_per_year')),
                TextInput::make('invoice_due_days')->label(__('admin.settings.due_days'))->numeric()->minValue(0)->required(),
                TextInput::make('invoice_reminder_days_before')->label(__('admin.settings.reminder_before'))->numeric()->minValue(0)->hintIcon(...$hint('reminder_before')),
                TextInput::make('invoice_overdue_days')->label(__('admin.settings.overdue_days'))->regex('/^\s*\d+(\s*,\s*\d+)*\s*$/')->hintIcon(...$hint('overdue_days')),
                TextInput::make('invoice_overdue_fees')->label(__('admin.settings.overdue_fees'))->regex('/^\s*\d+([.,]\d{1,2})?(\s*;\s*\d+([.,]\d{1,2})?)*\s*$/')->placeholder('0')->hintIcon(...$hint('overdue_fees')),
                Toggle::make('invoice_attach_paid')->label(__('admin.settings.attach_paid'))->inline(false),
            ]),
            // Expense claims (E1d.3): the four-eyes principle and the flat rates.
            Section::make(__('accounting.expenses.settings.section'))->columns(2)->schema([
                Toggle::make('expense_four_eyes')->label(__('accounting.expenses.settings.four_eyes'))->inline(false)
                    ->hintIcon('heroicon-m-question-mark-circle', __('accounting.expenses.settings.four_eyes_hint')),
                Textarea::make('expense_flat_rates')->label(__('accounting.expenses.settings.flat_rates'))->rows(3)->maxLength(2000)
                    ->hintIcon('heroicon-m-question-mark-circle', __('accounting.expenses.settings.flat_rates_hint'))
                    ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail) {
                        foreach (preg_split('/\R/', (string) $value) ?: [] as $line) {
                            if (trim($line) !== '' && preg_match(ExpenseClaims::FLAT_RATE_LINE, $line) !== 1) {
                                $fail(__('accounting.expenses.settings.flat_rates_invalid'));
                            }
                        }
                    }),
            ]),
        ];
    }

    protected function storing(string $key, mixed $value): mixed
    {
        return in_array($key, ['organisation_iban', 'organisation_bic'], true) ? strtoupper(str_replace(' ', '', (string) $value)) : $value;
    }

    public static function validIban(string $iban): bool
    {
        return Iban::valid($iban);
    }
}
