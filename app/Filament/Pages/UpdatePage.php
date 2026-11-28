<?php

namespace App\Filament\Pages;

use App\Filament\Support\InArea;
use App\Models\User;
use App\Support\System\SystemNotifications;
use App\Support\System\SystemStatus;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Update of Kasmin for administrators (B11): newer versions, their changes and the update in the browser.
 */
class UpdatePage extends Page
{
    use InArea;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static ?string $slug = 'updates';

    protected string $view = 'filament.pages.update';

    public static function getNavigationLabel(): string
    {
        return __('system.update.title');
    }

    public function getTitle(): string
    {
        return __('system.update.title');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(SystemNotifications::class)->administrators()->contains('id', $user->id);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return ['docker' => SystemStatus::runsInDocker()];
    }
}
