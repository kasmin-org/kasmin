<?php

namespace App\Filament\Widgets;

use App\Models\User;
use App\Support\Reports\DashboardData;
use Filament\Widgets\Widget;

/**
 * To-dos, finance, safety sheets and automations at a glance (AD02–AD05).
 */
class TodoOverview extends Widget
{
    protected string $view = 'filament.widgets.todo-overview';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 2;

    protected static bool $isLazy = false;

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $data = app(DashboardData::class);
        $user = auth()->user();
        $finance = $user instanceof User && $user->canViewFinance();

        return [
            'todos' => $data->todos(),
            'finance' => $finance ? $data->finance() : null,
            'safety' => $data->safetySheets(),
            'automations' => $data->automations(),
        ];
    }
}
