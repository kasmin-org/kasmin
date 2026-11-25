<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Auth\Permissions;
use App\Support\Mail\MailImages;
use App\Support\Settings;
use App\Support\System\Updater;
use BackedEnum;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

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
        'invoice_number_per_year', 'invoice_due_days', 'invoice_attach_paid', 'invoice_reminder_days_before', 'invoice_overdue_days', 'invoice_overdue_fees', 'public_url', 'mail_test_address', 'mailing_approval_above', 'backup_keep', 'embed_origins', 'timezone', 'digest_time', 'approval_days', 'ai_processing', 'retention_safety_years', 'retention_invoice_years',
        'max_guardians', 'min_login_age', 'guardian_until_age', 'own_registrations', 'newsletter_ask', 'mail_logo', 'mail_color', 'mail_footer', 'retention_mail_years', 'auto_update', 'auto_update_days', 'auto_update_time',
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

        return $user instanceof User && (Permissions::allows($user, 'settings', 'edit') || Permissions::allows($user, 'finance', 'edit'));
    }

    public function mount(): void
    {
        $data = [];
        foreach (self::KEYS as $key) {
            $data[$key] = Settings::get($key);
        }
        $data['mail_logo'] = Settings::get('mail_logo') ?: null;
        $data['organisation_name'] ??= config('app.name');
        foreach (['invoice_attach_paid', 'invoice_number_per_year', 'newsletter_ask', 'auto_update'] as $key) {
            $data[$key] = Settings::bool($key);
        }
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
            Section::make(__('admin.settings.system'))->visible(fn () => Permissions::administrator())->columns(3)->schema([
                TextInput::make('public_url')->label(__('admin.settings.public_url'))->url()->maxLength(200)->placeholder((string) config('app.url'))->hintIcon(...$hint('public_url')),
                TextInput::make('mail_test_address')->label(__('admin.settings.mail_test_address'))->email()->hintIcon(...$hint('mail_test_address')),
                TextInput::make('mailing_approval_above')->label(__('admin.settings.mailing_approval_above'))->numeric()->minValue(0)->required()->hintIcon(...$hint('mailing_approval_above')),
                TextInput::make('backup_keep')->label(__('admin.settings.backup_keep'))->numeric()->minValue(1)->maxValue(90)->required(),
                TextInput::make('embed_origins')->label(__('admin.settings.embed_origins'))->placeholder('https://www.example.org')->regex('#^\s*https://[a-zA-Z0-9.-]+(:\d+)?([\s,]+https://[a-zA-Z0-9.-]+(:\d+)?)*\s*$#')->hintIcon(...$hint('embed_origins')),
                Select::make('timezone')->label(__('admin.settings.timezone'))->searchable()->placeholder('UTC')->options(fn () => array_combine(timezone_identifiers_list(), timezone_identifiers_list()))->hintIcon(...$hint('timezone')),
                TextInput::make('digest_time')->label(__('admin.settings.digest_time'))->regex('/^([01]\d|2[0-3]):[0-5]\d$/')->placeholder('07:00'),
                TextInput::make('approval_days')->label(__('admin.settings.approval_days'))->numeric()->minValue(1)->maxValue(60)->placeholder('7'),
            ]),
            Section::make(__('admin.settings.mail_layout'))->visible(fn () => Permissions::current('settings', 'edit'))->columns(['md' => 2])->schema([
                FileUpload::make('mail_logo')->label(__('admin.settings.mail_logo'))->disk('local')->directory(MailImages::DIRECTORY)->visibility('private')->acceptedFileTypes(MailImages::TYPES)->maxSize(1024)
                    ->getUploadedFileNameForStorageUsing(fn (TemporaryUploadedFile $file) => Str::random(32).'.'.($file->getMimeType() === 'image/png' ? 'png' : ($file->getMimeType() === 'image/gif' ? 'gif' : 'jpg'))),
                ColorPicker::make('mail_color')->label(__('admin.settings.mail_color'))->regex('/^#[0-9a-fA-F]{6}$/')->required(),
                Textarea::make('mail_footer')->label(__('admin.settings.mail_footer'))->rows(3)->maxLength(1000)->columnSpanFull()->hintIcon(...$hint('mail_footer')),
            ]),
            Section::make(__('admin.settings.families'))->visible(fn () => Permissions::current('settings', 'edit'))->columns(2)->schema([
                TextInput::make('max_guardians')->label(__('admin.settings.max_guardians'))->numeric()->minValue(1)->maxValue(20)->required(),
                TextInput::make('min_login_age')->label(__('admin.settings.min_login_age'))->numeric()->minValue(6)->maxValue(18)->required(),
                TextInput::make('guardian_until_age')->label(__('admin.settings.guardian_until_age'))->numeric()->minValue(14)->maxValue(25)->required(),
                Select::make('own_registrations')->label(__('admin.settings.own_registrations'))->options(__('admin.settings.own_registrations_options'))->required()->hintIcon(...$hint('own_registrations')),
                Toggle::make('newsletter_ask')->label(__('admin.settings.newsletter_ask'))->inline(false)->hintIcon(...$hint('newsletter_ask')),
            ]),
            Section::make(__('admin.settings.retention'))->visible(fn () => Permissions::current('settings', 'edit'))->columns(2)->schema([
                TextInput::make('retention_safety_years')->label(__('admin.settings.retention_safety_years'))->numeric()->minValue(1)->maxValue(30)->required(),
                TextInput::make('retention_mail_years')->label(__('admin.settings.retention_mail_years'))->numeric()->minValue(0)->maxValue(30)->required()->hintIcon(...$hint('retention_mail_years')),
                TextInput::make('retention_invoice_years')->label(__('admin.settings.retention_invoice_years'))->numeric()->minValue(1)->maxValue(30)->required(),
            ]),
            Section::make(__('system.auto_update.title'))->visible(fn () => Permissions::administrator())->description(fn () => app(Updater::class)->available() ? __('system.auto_update.hint') : __('system.update.docker'))->columns(['md' => 3])->schema([
                Toggle::make('auto_update')->label(__('system.auto_update.enabled'))->inline(false)->disabled(fn () => ! app(Updater::class)->available()),
                TextInput::make('auto_update_days')->label(__('system.auto_update.days'))->numeric()->minValue(0)->maxValue(90)->required(),
                TextInput::make('auto_update_time')->label(__('system.auto_update.time'))->regex('/^([01]\d|2[0-3]):[0-5]\d$/')->required(),
            ]),
            Section::make(__('admin.settings.ai'))->visible(fn () => Permissions::administrator())->schema([
                Radio::make('ai_processing')->label(__('admin.settings.ai_processing'))->options(__('admin.settings.ai_options'))->required()
                    ->helperText(__('admin.settings.ai_hint')),
            ]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        foreach (self::KEYS as $key) {
            if (! array_key_exists($key, $data)) {
                continue; // sections this person may not change
            }
            $value = $data[$key];
            if (in_array($key, ['invoice_attach_paid', 'invoice_number_per_year', 'newsletter_ask', 'auto_update'], true)) {
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
