<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Settings;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Organisation and invoice settings (part of S01): name, address, bank account for invoices and the EPC QR code,
 * legal footer, invoice numbering, due dates and reminders.
 *
 * @property-read Schema $form
 */
class OrganisationSettingsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $slug = 'settings/organisation';

    protected string $view = 'filament.pages.organisation-settings';

    /** @var array<string, mixed> */
    public array $data = [];

    private const KEYS = [
        'organisation_name', 'organisation_address', 'organisation_email', 'organisation_website', 'organisation_iban',
        'organisation_bic', 'organisation_bank', 'organisation_legal_footer', 'invoice_number_format', 'invoice_next_number',
        'invoice_due_days', 'invoice_attach_paid', 'invoice_reminder_days_before', 'invoice_overdue_days',
    ];

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.settings.organisation');
    }

    public function getTitle(): string
    {
        return __('admin.settings.organisation');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasAnyRole(['administrator', 'board', 'treasurer']);
    }

    public function mount(): void
    {
        $data = [];
        foreach (self::KEYS as $key) {
            $data[$key] = Settings::get($key);
        }
        $data['organisation_name'] ??= config('app.name');
        $data['invoice_attach_paid'] = Settings::bool('invoice_attach_paid');
        $this->form->fill($data);
    }

    public function form(Schema $schema): Schema
    {
        $hint = fn (string $key) => ['heroicon-m-question-mark-circle', __('admin.settings.'.$key.'_hint')];

        return $schema->statePath('data')->components([
            Section::make(__('admin.settings.organisation'))->columns(2)->schema([
                TextInput::make('organisation_name')->label(__('admin.settings.name'))->required()->maxLength(70),
                TextInput::make('organisation_email')->label(__('admin.settings.email'))->email(),
                Textarea::make('organisation_address')->label(__('admin.settings.address'))->rows(3),
                TextInput::make('organisation_website')->label(__('admin.settings.website'))->url(),
                Textarea::make('organisation_legal_footer')->label(__('admin.settings.legal_footer'))->rows(3)->columnSpanFull()
                    ->hintIcon(...$hint('legal_footer')),
            ]),
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
                TextInput::make('invoice_number_format')->label(__('admin.settings.number_format'))->required()->hintIcon(...$hint('number_format')),
                TextInput::make('invoice_next_number')->label(__('admin.settings.next_number'))->numeric()->minValue(1)->required()->hintIcon(...$hint('next_number')),
                TextInput::make('invoice_due_days')->label(__('admin.settings.due_days'))->numeric()->minValue(0)->required(),
                TextInput::make('invoice_reminder_days_before')->label(__('admin.settings.reminder_before'))->numeric()->minValue(0)->hintIcon(...$hint('reminder_before')),
                TextInput::make('invoice_overdue_days')->label(__('admin.settings.overdue_days'))->regex('/^\s*\d+(\s*,\s*\d+)*\s*$/')->hintIcon(...$hint('overdue_days')),
                Toggle::make('invoice_attach_paid')->label(__('admin.settings.attach_paid'))->inline(false),
            ]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        foreach (self::KEYS as $key) {
            $value = $data[$key] ?? null;
            if ($key === 'invoice_attach_paid') {
                $value = $value ? '1' : '0';
            }
            if (in_array($key, ['organisation_iban', 'organisation_bic'], true)) {
                $value = strtoupper(str_replace(' ', '', (string) $value));
            }
            Settings::set($key, $value === null ? null : (string) $value);
        }
        Audit::record('settings.updated', null, null, ['section' => 'organisation']);
        Notification::make()->title(__('admin.settings.saved'))->success()->send();
    }

    public static function validIban(string $iban): bool
    {
        $iban = strtoupper(str_replace(' ', '', $iban));
        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $iban) !== 1) {
            return false;
        }
        $numeric = '';
        foreach (str_split(substr($iban, 4).substr($iban, 0, 4)) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        return bcmod($numeric, '97') === '1';
    }
}
