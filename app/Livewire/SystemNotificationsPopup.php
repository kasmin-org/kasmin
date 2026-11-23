<?php

namespace App\Livewire;

use App\Models\SystemNotification;
use App\Models\User;
use App\Support\System\SystemNotifications;
use App\Support\System\SystemStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Pops up open system notifications for administrators in the admin interface until they are dismissed (SN01).
 * The checks also run here (at most every five minutes), so a missing cron job is noticed without the scheduler.
 */
class SystemNotificationsPopup extends Component
{
    public function dismiss(int $id): void
    {
        $user = auth()->user();
        if ($user instanceof User) {
            app(SystemNotifications::class)->dismiss(SystemNotification::query()->findOrFail($id), $user);
        }
    }

    public function render(): View
    {
        $user = auth()->user();
        $items = collect();
        if ($user instanceof User) {
            $service = app(SystemNotifications::class);
            if ($service->administrators()->contains('id', $user->id)) {
                Cache::remember('kasmin.system_check', now()->addMinutes(5), function () use ($service) {
                    app(SystemStatus::class)->notify($service);

                    return true;
                });
                $items = $service->openFor($user);
            }
        }

        return view('livewire.system-notifications-popup', ['items' => $items]);
    }
}
