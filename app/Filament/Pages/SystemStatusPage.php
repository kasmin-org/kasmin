<?php

namespace App\Filament\Pages;

use App\Filament\Support\InArea;
use App\Models\SystemNotification;
use App\Models\User;
use App\Support\Mail\MailDns;
use App\Support\System\SystemNotifications;
use App\Support\System\SystemStatus;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * System status for administrators (ST01, ST02): checks, PHP configuration, mail DNS records, system notifications.
 * Backups and the update have their own pages since B11.
 */
class SystemStatusPage extends Page
{
    use InArea;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static ?string $slug = 'system';

    protected string $view = 'filament.pages.system-status';

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
            'cronDocs' => SystemStatus::docsUrl('admin/hosting'),
        ];
    }
}
