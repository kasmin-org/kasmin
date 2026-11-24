<?php

namespace App\Filament\Pages;

use App\Models\Person;
use App\Models\User;
use App\Support\Auth\Permissions;
use App\Support\Privacy\Privacy;
use App\Support\Settings;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Data protection (AD71, AD72): deletion requests with a preview of what is deleted and what must be kept, and the
 * retention run for confidential form answers.
 */
class PrivacyPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $slug = 'privacy';

    protected string $view = 'filament.pages.privacy';

    public ?int $reviewing = null;

    public string $reason = '';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.privacy');
    }

    public static function getNavigationLabel(): string
    {
        return __('privacy.title');
    }

    public function getTitle(): string
    {
        return __('privacy.title');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && Permissions::allows($user, 'privacy', 'edit');
    }

    public function review(int $person): void
    {
        $this->reviewing = $person;
        $this->reason = (string) __('privacy.default_reason');
    }

    public function anonymise(): void
    {
        $person = Person::query()->whereNull('anonymised_at')->find($this->reviewing);
        if ($person === null || trim($this->reason) === '') {
            return;
        }
        try {
            app(Privacy::class)->anonymise($person, $this->reason);
        } catch (\RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }
        $this->reviewing = null;
        Notification::make()->title(__('privacy.done'))->success()->send();
    }

    public function runRetention(): void
    {
        Notification::make()->title(__('privacy.retention_done', ['count' => app(Privacy::class)->runRetention()]))->success()->send();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $privacy = app(Privacy::class);
        $person = $this->reviewing !== null ? Person::query()->find($this->reviewing) : null;

        return [
            'requests' => $privacy->openRequests(),
            'person' => $person,
            'plan' => $person !== null ? $privacy->plan($person) : null,
            'retention' => $privacy->retentionPlan(),
            'years' => Settings::get('retention_safety_years'),
        ];
    }
}
