<?php

namespace App\Filament\Widgets;

use App\Models\User;
use App\Support\Auth\Permissions;
use App\Support\Reports\DashboardData;
use Filament\Widgets\Widget;

/**
 * One block per event series with its next event (AD01, AD01a).
 */
class EventSeriesOverview extends Widget
{
    protected string $view = 'filament.widgets.event-series-overview';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 1;

    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return Permissions::current('events') || Permissions::current('finance');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $user = auth()->user();

        return ['blocks' => app(DashboardData::class)->series($user instanceof User && $user->canViewFinance())];
    }
}
