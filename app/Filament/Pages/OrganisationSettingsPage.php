<?php

namespace App\Filament\Pages;

use App\Filament\Support\InArea;
use App\Filament\Support\SettingsPage;
use App\Models\User;
use App\Support\Auth\Permissions;
use App\Support\Language;
use App\Support\Mail\MailImages;
use App\Support\Settings;
use BackedEnum;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Settings of the organisation (part of S01): name, address, legal footer, look of the mails, families and how long data is kept.
 */
class OrganisationSettingsPage extends SettingsPage
{
    use InArea;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $slug = 'settings/organisation';

    public static function getNavigationLabel(): string
    {
        return __('admin.settings.organisation_page');
    }

    public function getTitle(): string
    {
        return __('admin.settings.organisation_page');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && (Permissions::allows($user, 'settings', 'edit'));
    }

    protected static function keys(): array
    {
        return ['organisation_name', 'organisation_address', 'organisation_email', 'organisation_website', 'organisation_legal_footer', 'mail_logo', 'mail_color', 'mail_footer',
            'max_guardians', 'own_event_days', 'min_login_age', 'guardian_until_age', 'own_registrations', 'gender_note_female', 'gender_note_male', 'gender_note_diverse',
            'retention_safety_years', 'retention_mail_years', 'retention_invoice_years', 'audit_ip_days', 'retention_audit_years'];
    }

    protected static function switches(): array
    {
        return ['newsletter_ask'];
    }

    protected static function section(): string
    {
        return 'organisation';
    }

    protected function sections(): array
    {
        $hint = fn (string $key) => self::hint($key);

        return [
            Section::make(__('admin.settings.organisation'))->columns(2)->schema([
                TextInput::make('organisation_name')->label(__('admin.settings.name'))->required()->maxLength(70),
                TextInput::make('organisation_email')->label(__('admin.settings.email'))->email(),
                Textarea::make('organisation_address')->label(__('admin.settings.address'))->rows(3),
                TextInput::make('organisation_website')->label(__('admin.settings.website'))->url(),
                Textarea::make('organisation_legal_footer')->label(__('admin.settings.legal_footer'))->rows(3)->columnSpanFull()
                    ->hintIcon(...$hint('legal_footer')),
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
                TextInput::make('own_event_days')->label(__('admin.settings.own_event_days'))->numeric()->minValue(0)->maxValue(3650)->required()->hintIcon(...$hint('own_event_days')),
                Select::make('own_registrations')->label(__('admin.settings.own_registrations'))->options(__('admin.settings.own_registrations_options'))->required()->hintIcon(...$hint('own_registrations')),
                Toggle::make('newsletter_ask')->label(__('admin.settings.newsletter_ask'))->inline(false)->hintIcon(...$hint('newsletter_ask')),
                Textarea::make('gender_note_female')->label(__('admin.settings.gender_note', ['gender' => __('admin.genders.female')]))->rows(2)->maxLength(1000)->columnSpanFull()->hintIcon(...$hint('gender_note')),
                Textarea::make('gender_note_male')->label(__('admin.settings.gender_note', ['gender' => __('admin.genders.male')]))->rows(2)->maxLength(1000)->columnSpanFull()->hintIcon(...$hint('gender_note')),
                Textarea::make('gender_note_diverse')->label(__('admin.settings.gender_note', ['gender' => __('admin.genders.diverse')]))->rows(3)->maxLength(1000)->columnSpanFull()->hintIcon(...$hint('gender_note')),
            ]),
            Section::make(__('admin.settings.retention'))->visible(fn () => Permissions::current('settings', 'edit'))->columns(2)->schema([
                TextInput::make('retention_safety_years')->label(__('admin.settings.retention_safety_years'))->numeric()->minValue(1)->maxValue(30)->required(),
                TextInput::make('retention_mail_years')->label(__('admin.settings.retention_mail_years'))->numeric()->minValue(0)->maxValue(30)->required()->hintIcon(...$hint('retention_mail_years')),
                TextInput::make('retention_invoice_years')->label(__('admin.settings.retention_invoice_years'))->numeric()->minValue(1)->maxValue(30)->required(),
                TextInput::make('audit_ip_days')->label(__('admin.settings.audit_ip_days'))->numeric()->minValue(0)->maxValue(3650)->required()->hintIcon(...$hint('audit_ip_days')),
                TextInput::make('retention_audit_years')->label(__('admin.settings.retention_audit_years'))->numeric()->minValue(0)->maxValue(50)->required()->hintIcon(...$hint('retention_audit_years')),
            ]),
        ];
    }

    protected function filling(array &$data): void
    {
        $data['mail_logo'] = Settings::get('mail_logo') ?: null;
        $data['gender_note_diverse'] ??= __('portal.gender_note_diverse');
        $data['organisation_name'] ??= config('app.name');
    }

    protected function storing(string $key, mixed $value): mixed
    {
        if (str_starts_with($key, 'gender_note_')) {
            // The unchanged standard text stays "not set", so it keeps following the language of the reader.
            return $key === 'gender_note_diverse' && trim((string) $value) === __('portal.gender_note_diverse') ? null : trim((string) $value);
        }

        return $value;
    }
}
