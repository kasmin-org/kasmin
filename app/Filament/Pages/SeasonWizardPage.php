<?php

namespace App\Filament\Pages;

use App\Filament\Resources\AutomationRuns\AutomationRunResource;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Support\InArea;
use App\Models\Event;
use App\Models\User;
use App\Support\Auth\Permissions;
use App\Support\Events\SeasonWizard;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

/**
 * Wizard for a new season: copy the last event, see everything that will happen, then create it (AD32).
 */
class SeasonWizardPage extends Page
{
    use InArea;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $slug = 'season';

    protected string $view = 'filament.pages.season-wizard';

    #[Url]
    public ?int $source = null;

    public string $newTitle = '';

    public string $startsOn = '';

    public bool $season = false;

    public bool $checked = false;

    public static function getNavigationLabel(): string
    {
        return __('events.season.title');
    }

    public function getTitle(): string
    {
        return __('events.season.title');
    }

    public static function canAccess(): bool
    {
        return Permissions::current('events', 'edit');
    }

    public function mount(): void
    {
        $this->source ??= Event::query()->whereNotNull('event_series_id')->where('starts_at', '<', now()->addYear())->orderByDesc('starts_at')->value('id');
        $this->suggest();
    }

    public function updatedSource(): void
    {
        $this->suggest();
    }

    public function updated(): void
    {
        $this->checked = false;
    }

    public function check(): void
    {
        $this->validate(['source' => ['required', 'integer', 'exists:events,id'], 'newTitle' => ['required', 'string', 'max:200'], 'startsOn' => ['required', 'date', 'after:today']]);
        $this->checked = true;
    }

    public function create(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->check();
        $wizard = app(SeasonWizard::class);
        $event = $wizard->copy($this->event() ?? abort(404), $this->newTitle, Carbon::parse($this->startsOn));
        $user = auth()->user();
        if ($this->season && Permissions::current('automations', 'edit') && $user instanceof User) {
            $run = $wizard->start($event, $user);
            Notification::make()->title(__($run === null ? 'automations.nothing' : 'automations.started', ['status' => $run === null ? '' : __('automations.statuses.'.$run->status)]))->success()->send();
            if ($run !== null) {
                $this->redirect(AutomationRunResource::getUrl('view', ['record' => $run]));

                return;
            }
        }
        $this->redirect(EventResource::getUrl('edit', ['record' => $event]));
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $event = $this->event();
        $wizard = app(SeasonWizard::class);

        return [
            'events' => Event::query()->orderByDesc('starts_at')->limit(50)->pluck('title', 'id')->all(),
            'preview' => $this->checked && $event !== null ? $wizard->preview($event, $this->newTitle, Carbon::parse($this->startsOn)) : null,
            'mayStart' => $wizard->automation() !== null && Permissions::current('automations', 'edit'),
        ];
    }

    private function event(): ?Event
    {
        return $this->source === null ? null : Event::query()->find($this->source);
    }

    private function suggest(): void
    {
        $event = $this->event();
        if ($event === null) {
            return;
        }
        // The same weekday a year later.
        $this->newTitle = str_replace((string) $event->starts_at->year, (string) ($event->starts_at->year + 1), $event->title);
        $next = $event->starts_at->copy()->addWeeks(52);
        $this->startsOn = ($next->isPast() ? now()->addYear() : $next)->toDateString();
        $this->checked = false;
    }
}
