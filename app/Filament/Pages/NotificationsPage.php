<?php

namespace App\Filament\Pages;

use App\Models\NotificationEvent;
use App\Models\NotificationSubscription;
use App\Models\User;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Notifications of the logged-in person: which topics at once or as a digest (NT01), and the latest ones (NT05). Only
 * topics the person has the rights for.
 */
class NotificationsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static ?string $slug = 'notifications';

    protected string $view = 'filament.pages.notifications';

    /** @var array<string, string> */
    public array $choices = [];

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.system');
    }

    public static function getNavigationLabel(): string
    {
        return __('mail.notifications');
    }

    public function getTitle(): string
    {
        return __('mail.notifications');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && NotificationSubscription::topicsFor($user) !== [];
    }

    public function mount(): void
    {
        $user = $this->user();
        $chosen = NotificationSubscription::query()->where('user_id', $user->id)->pluck('frequency', 'topic');
        foreach (NotificationSubscription::topicsFor($user) as $topic) {
            $this->choices[$topic] = (string) ($chosen[$topic] ?? ($user->hasAnyRole(['administrator']) ? NotificationSubscription::ADMINISTRATOR_DEFAULTS[$topic] ?? 'off' : 'off'));
        }
    }

    public function save(): void
    {
        $user = $this->user();
        foreach (NotificationSubscription::topicsFor($user) as $topic) {
            $frequency = $this->choices[$topic] ?? 'off';
            if (! in_array($frequency, NotificationSubscription::FREQUENCIES, true)) {
                continue;
            }
            $subscription = NotificationSubscription::query()->firstOrNew(['user_id' => $user->id, 'topic' => $topic]);
            // A digest starts now, not with everything from before.
            $subscription->fill(['frequency' => $frequency, 'last_sent_at' => $subscription->last_sent_at ?? now()])->save();
        }
        Notification::make()->title(__('admin.settings.saved'))->success()->send();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $topics = NotificationSubscription::topicsFor($this->user());

        return ['topics' => $topics, 'latest' => NotificationEvent::query()->whereIn('topic', $topics)->latest('id')->limit(50)->get()];
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
