<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\NotificationsPage;
use App\Filament\Support\Areas;
use App\Filament\Support\InitialsAvatar;
use App\Http\Middleware\RequireFullLogin;
use App\Http\Middleware\RequireSecondFactor;
use App\Http\Middleware\SetLocale;
use App\Livewire\SystemNotificationsPopup;
use App\Support\Mail\Sender;
use App\Support\System\Docs;
use Filament\Actions\Action;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Livewire\Livewire;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->colors(['primary' => Color::hex('#1DA029')])
            ->brandLogo(fn () => asset('brand/kasmin-logo-horizontal.svg'))
            ->darkModeBrandLogo(fn () => asset('brand/kasmin-logo-horizontal-on-dark.svg'))
            ->brandLogoHeight('2rem')
            ->favicon(asset('favicon.svg'))
            ->defaultAvatarProvider(InitialsAvatar::class)
            ->globalSearchKeyBindings(['mod+k'])
            ->viteTheme('resources/css/filament/admin/theme.css')
            // Five areas at the top, the navigation on the left only of the open area (B11, Areas).
            ->navigationGroups(array_map(
                fn (string $key) => NavigationGroup::make()->label(fn (): string => (string) __('admin.nav.'.$key)),
                Areas::GROUPS,
            ))
            ->renderHook(PanelsRenderHook::TOPBAR_LOGO_AFTER, fn () => view('filament.areas.top'))
            ->renderHook(PanelsRenderHook::SIDEBAR_NAV_START, fn () => view('filament.areas.side'))
            ->userMenuItems([
                Action::make('notifications')->label(fn () => __('mail.notifications'))->icon('heroicon-o-bell')->url(fn () => NotificationsPage::getUrl())
                    ->visible(fn () => NotificationsPage::canAccess()),
            ])
            ->renderHook(PanelsRenderHook::CONTENT_START, fn () => Sender::testAddress() !== null ? view('filament.test-mode', ['address' => Sender::testAddress()]) : '')
            ->renderHook(PanelsRenderHook::CONTENT_START, fn () => Livewire::mount(SystemNotificationsPopup::class))
            ->renderHook(PanelsRenderHook::PAGE_HEADER_HEADING_AFTER, fn () => view('filament.help-link', ['url' => Docs::forRoute(request()->route()?->getName())]))
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                SetLocale::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                RequireSecondFactor::class,
            ])
            // Also on later Livewire requests: links in mails never open the admin interface.
            ->authMiddleware([RequireFullLogin::class], isPersistent: true);
    }
}
