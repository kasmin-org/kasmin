<?php

namespace App\Filament\Pages;

use App\Models\Event;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Auth\Permissions;
use App\Support\Lists\EventLists;
use App\Support\Lists\MissingSheets;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/**
 * Lists and printouts of an event in one place (AD33, EC03, SF25, SF26).
 */
class EventListsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static ?string $slug = 'lists';

    protected static ?int $navigationSort = 30;

    protected string $view = 'filament.pages.event-lists';

    #[Url]
    public ?int $event = null;

    #[Url]
    public string $list = 'participants';

    #[Url]
    public string $role = '';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.events');
    }

    public static function getNavigationLabel(): string
    {
        return __('lists.title');
    }

    public function getTitle(): string
    {
        return __('lists.title');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && EventLists::keysFor($user) !== [];
    }

    public function mount(): void
    {
        $this->event ??= Event::query()->whereNotIn('status', ['draft', 'archived'])->where('ends_at', '>=', now()->subMonths(2))->whereHas('participations')->orderBy('starts_at')->value('id')
            ?? Event::query()->whereNotIn('status', ['draft', 'archived'])->where('ends_at', '>=', now()->subMonths(2))->orderBy('starts_at')->value('id')
            ?? Event::query()->orderByDesc('starts_at')->value('id');
        $this->logView();
    }

    public function updated(): void
    {
        $this->logView();
    }

    private function logView(): void
    {
        $user = auth()->user();
        if ($user instanceof User && (EventLists::LISTS[$this->list] ?? false) && EventLists::mayView($user, $this->list) && $this->event !== null) {
            Audit::record('list.viewed', Event::query()->find($this->event), null, ['list' => $this->list]);
        }
    }

    /** @var list<string> keys of the selected families */
    public array $selected = [];

    /** One-click reminder to the selected families in the list "still missing" (SF19a). */
    public function remind(): void
    {
        abort_unless(Permissions::current('events', 'edit') || Permissions::current('safety', 'edit'), 403);
        $event = Event::query()->find($this->event);
        if ($event === null || $this->selected === []) {
            return;
        }
        $result = app(MissingSheets::class)->remind($event, $this->selected, $this->role ?: null);
        $this->selected = [];
        Notification::make()->title(__('lists.reminded', $result))->success()->send();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $event = $this->event !== null ? Event::query()->with('roles')->find($this->event) : null;
        $keys = EventLists::keysFor($user);
        $key = in_array($this->list, $keys, true) ? $this->list : 'participants';

        return [
            'events' => Event::query()->orderByDesc('starts_at')->limit(100)->pluck('title', 'id')->all(),
            'model' => $event, 'keys' => $keys, 'key' => $key, 'roleOptions' => $event?->roles->pluck('role_key')->all() ?? [],
            'table' => $event !== null ? app(EventLists::class)->build($event, $key, $this->role ?: null) : null,
            'safety' => EventLists::mayViewSafety($user),
            'families' => $event !== null && $key === 'missing' && (Permissions::current('events', 'edit') || Permissions::current('safety', 'edit')) ? app(MissingSheets::class)->labels($event, $this->role ?: null) : [],
        ];
    }
}
