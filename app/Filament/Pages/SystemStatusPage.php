<?php

namespace App\Filament\Pages;

use App\Models\SystemNotification;
use App\Models\User;
use App\Support\Backup\Backups;
use App\Support\Backup\RemoteBackup;
use App\Support\Mail\MailDns;
use App\Support\System\SystemNotifications;
use App\Support\System\SystemStatus;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * System status for administrators (ST01, ST02): checks, mail DNS records, system notifications, update.
 */
class SystemStatusPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static ?string $slug = 'system';

    protected string $view = 'filament.pages.system-status';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.system');
    }

    public static function getNavigationLabel(): string
    {
        return __('system.status.title');
    }

    public function getTitle(): string
    {
        return __('system.status.title');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(SystemNotifications::class)->administrators()->contains('id', $user->id);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $status = app(SystemStatus::class);
        $status->notify(app(SystemNotifications::class));

        return [
            'checks' => $status->checks(),
            'dns' => app(MailDns::class)->report(),
            'notifications' => SystemNotification::query()->whereNull('resolved_at')->orderByDesc('id')->get(),
            'docker' => SystemStatus::runsInDocker(),
            'backups' => array_slice(app(Backups::class)->all(), 0, 12),
            'remote' => RemoteBackup::configured() ? RemoteBackup::state() : null,
            'passphrase' => RemoteBackup::secret('backup_passphrase') !== '',
            'cronDocs' => SystemStatus::docsUrl('admin/hosting'),
        ];
    }
}
