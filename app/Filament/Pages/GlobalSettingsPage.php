<?php

namespace App\Filament\Pages;

use App\Filament\Support\InArea;
use App\Filament\Support\SettingsPage;
use App\Models\User;
use App\Support\Accounting\Accounting;
use App\Support\Auth\Permissions;
use App\Support\Backup\RemoteBackup;
use App\Support\Language;
use App\Support\Settings;
use App\Support\System\Updater;
use BackedEnum;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;

/**
 * Global settings (S01, B11), for administrators only: language of the installation, public address, test mode, times, approvals, backups, automatic updates and AI.
 */
class GlobalSettingsPage extends SettingsPage
{
    use InArea;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $slug = 'settings/global';

    public static function getNavigationLabel(): string
    {
        return __('admin.settings.global_page');
    }

    public function getTitle(): string
    {
        return __('admin.settings.global_page');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && (Permissions::administrator());
    }

    protected static function keys(): array
    {
        return ['default_locale', 'public_url', 'mail_test_address', 'mailing_approval_above', 'backup_keep', 'embed_origins', 'timezone', 'digest_time', 'approval_days',
            'approval_summary_time', 'ai_processing', 'backup_remote_url', 'backup_remote_user', 'auto_update_days', 'auto_update_time'];
    }

    protected static function switches(): array
    {
        return ['auto_update', 'use_cost_centers', 'use_funds'];
    }

    protected static function section(): string
    {
        return 'global';
    }

    protected function sections(): array
    {
        $hint = fn (string $key) => self::hint($key);

        return [
            Section::make(__('accounting.switch.title'))->description(__('accounting.switch.intro'))->columns(['md' => 2])->schema([
                Toggle::make('use_cost_centers')->label(__('accounting.switch.cost_centers'))->helperText(__('accounting.switch.cost_centers_hint'))->live()
                    ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail) {
                        if (! $value && Accounting::costCenters() && ($why = Accounting::whyCostCentersStay()) !== null) {
                            $fail($why);
                        }
                    }),
                Toggle::make('use_funds')->label(__('accounting.switch.funds'))->helperText(__('accounting.switch.funds_hint'))
                    ->disabled(fn ($get) => ! $get('use_cost_centers'))->dehydrated()
                    ->rule(fn ($get) => function (string $attribute, mixed $value, \Closure $fail) use ($get) {
                        if ($value && ! $get('use_cost_centers')) {
                            $fail(__('accounting.switch.cost_centers_first'));
                        } elseif (! $value && Accounting::funds() && ($why = Accounting::whyFundsStay()) !== null) {
                            $fail($why);
                        }
                    }),
            ]),
            Section::make(__('admin.settings.system'))->visible(fn () => Permissions::administrator())->columns(3)->schema([
                Select::make('default_locale')->label(__('admin.settings.language'))->options(['de' => 'Deutsch', 'en' => 'English'])->required()->selectablePlaceholder(false)->hintIcon(...$hint('language')),
                TextInput::make('public_url')->label(__('admin.settings.public_url'))->url()->maxLength(200)->placeholder((string) config('app.url'))->hintIcon(...$hint('public_url')),
                TextInput::make('mail_test_address')->label(__('admin.settings.mail_test_address'))->email()->hintIcon(...$hint('mail_test_address')),
                TextInput::make('mailing_approval_above')->label(__('admin.settings.mailing_approval_above'))->numeric()->minValue(0)->required()->hintIcon(...$hint('mailing_approval_above')),
                TextInput::make('backup_keep')->label(__('admin.settings.backup_keep'))->numeric()->minValue(1)->maxValue(90)->required(),
                TextInput::make('embed_origins')->label(__('admin.settings.embed_origins'))->placeholder('https://www.example.org')->regex('#^\s*https://[a-zA-Z0-9.-]+(:\d+)?([\s,]+https://[a-zA-Z0-9.-]+(:\d+)?)*\s*$#')->hintIcon(...$hint('embed_origins')),
                Select::make('timezone')->label(__('admin.settings.timezone'))->searchable()->placeholder('UTC')->options(fn () => array_combine(timezone_identifiers_list(), timezone_identifiers_list()))->hintIcon(...$hint('timezone')),
                TextInput::make('digest_time')->label(__('admin.settings.digest_time'))->regex('/^([01]\d|2[0-3]):[0-5]\d$/')->placeholder('07:00'),
                TextInput::make('approval_days')->label(__('admin.settings.approval_days'))->numeric()->minValue(1)->maxValue(60)->placeholder('7'),
                TextInput::make('approval_summary_time')->label(__('admin.settings.approval_summary_time'))->regex('/^\s*(([01]\d|2[0-3]):[0-5]\d(\s*,\s*([01]\d|2[0-3]):[0-5]\d)*)?\s*$/')->placeholder('07:00')->hintIcon(...$hint('approval_summary_time')),
            ]),
            Section::make(__('system.auto_update.title'))->visible(fn () => Permissions::administrator())->description(fn () => app(Updater::class)->available() ? __('system.auto_update.hint') : __('system.update.docker'))->columns(['md' => 3])->schema([
                Toggle::make('auto_update')->label(__('system.auto_update.enabled'))->inline(false)->disabled(fn () => ! app(Updater::class)->available()),
                TextInput::make('auto_update_days')->label(__('system.auto_update.days'))->numeric()->minValue(0)->maxValue(90)->required(),
                TextInput::make('auto_update_time')->label(__('system.auto_update.time'))->regex('/^([01]\d|2[0-3]):[0-5]\d$/')->required(),
            ]),
            Section::make(__('system.backup.remote'))->visible(fn () => Permissions::administrator())->description(__('system.backup.remote_hint'))->columns(2)->schema([
                TextInput::make('backup_remote_url')->label(__('system.backup.url'))->url()->startsWith('https://')->maxLength(500)->placeholder('https://cloud.example.org/remote.php/dav/files/name/backups'),
                TextInput::make('backup_remote_user')->label(__('system.backup.user'))->maxLength(200),
                TextInput::make('backup_remote_password')->label(__('system.backup.password'))->password()->maxLength(200)->placeholder(fn () => RemoteBackup::secret('backup_remote_password') !== '' ? __('system.backup.kept') : null),
                TextInput::make('backup_passphrase')->label(__('system.backup.passphrase'))->password()->minLength(12)->maxLength(200)->placeholder(fn () => RemoteBackup::secret('backup_passphrase') !== '' ? __('system.backup.kept') : null)
                    ->helperText(__('system.backup.passphrase_hint')),
            ]),
            Section::make(__('admin.settings.ai'))->visible(fn () => Permissions::administrator())->schema([
                Radio::make('ai_processing')->label(__('admin.settings.ai_processing'))->options(__('admin.settings.ai_options'))->required()
                    ->helperText(__('admin.settings.ai_hint')),
            ]),
        ];
    }

    protected function filling(array &$data): void
    {
        $data['default_locale'] = Language::current();
    }

    protected function saved(array $data): void
    {
        foreach (['backup_remote_password', 'backup_passphrase'] as $secret) { // empty = unchanged; never shown again
            if (filled($data[$secret] ?? null)) {
                RemoteBackup::storeSecret($secret, (string) $data[$secret]);
            }
        }
    }
}
