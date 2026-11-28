<?php

namespace App\Filament\Pages;

use App\Filament\Support\InArea;
use App\Models\Event;
use App\Models\User;
use App\Support\Auth\Permissions;
use App\Support\Reports\Statistics;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Reports per event (AD60, AD62–AD65): age distribution, age groups for activities, costs and income, discounts,
 * participants per year.
 */
class StatisticsPage extends Page
{
    use InArea;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $slug = 'reports';

    protected string $view = 'filament.pages.statistics';

    public ?int $event = null;

    public int $groups = 3;

    public string $role = 'child';

    public static function getNavigationLabel(): string
    {
        return __('reports.title');
    }

    public function getTitle(): string
    {
        return __('reports.title');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && (Permissions::allows($user, 'events') || Permissions::allows($user, 'finance'));
    }

    public function mount(): void
    {
        // The next event with participants, otherwise the next or the latest one.
        $this->event = Event::query()->whereNotIn('status', ['draft', 'cancelled'])->where('ends_at', '>=', now()->subMonths(2))->whereHas('participations')->orderBy('starts_at')->value('id')
            ?? Event::query()->whereNotIn('status', ['draft', 'cancelled'])->where('ends_at', '>=', now()->subMonths(2))->orderBy('starts_at')->value('id')
            ?? Event::query()->orderByDesc('starts_at')->value('id');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $stats = app(Statistics::class);
        $event = $this->event !== null ? Event::query()->with('roles')->find($this->event) : null;
        $user = auth()->user();
        $roles = $this->role === '' ? [] : [$this->role];

        return [
            'events' => Event::query()->orderByDesc('starts_at')->limit(100)->pluck('title', 'id')->all(),
            'model' => $event,
            'roleOptions' => $event?->roles->pluck('role_key')->all() ?? [],
            'ages' => $event ? $stats->ageDistribution($event, $roles) : [],
            'ageGroups' => $event && Permissions::current('events') ? $stats->ageGroups($event, $this->groups, $roles) : [],
            'costs' => $event && $user instanceof User && $user->canViewFinance() ? $stats->costs($event) : null,
            'discounts' => $event && $user instanceof User && $user->canViewFinance() ? $stats->discounts($event) : null,
            'years' => $stats->years(),
            'mayExport' => Permissions::current('events') && Permissions::current('people'),
            'exportFields' => Statistics::AGE_GROUP_FIELDS,
        ];
    }
}
