<?php

namespace App\Filament\Pages;

use App\Filament\Support\InArea;
use App\Models\User;
use App\Support\Backup\Backups;
use App\Support\Backup\DatabaseRestore;
use App\Support\Backup\RemoteBackup;
use App\Support\System\SystemNotifications;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Backups for administrators (ST05, B11): the nightly backups, a backup now, download, restore and decrypting a copy.
 */
class BackupsPage extends Page
{
    use InArea;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static ?string $slug = 'backups';

    protected string $view = 'filament.pages.backups';

    public static function getNavigationLabel(): string
    {
        return __('system.backup.title');
    }

    public function getTitle(): string
    {
        return __('system.backup.title');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(SystemNotifications::class)->administrators()->contains('id', $user->id);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            'backups' => array_slice(app(Backups::class)->all(), 0, 12),
            'remote' => RemoteBackup::configured() ? RemoteBackup::state() : null,
            'passphrase' => RemoteBackup::secret('backup_passphrase') !== '',
            'restore' => DatabaseRestore::available(),
        ];
    }
}
