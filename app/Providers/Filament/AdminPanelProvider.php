<?php

namespace App\Providers\Filament;

use App\Http\Middleware\RequireFullLogin;
use App\Http\Middleware\RequireSecondFactor;
use App\Http\Middleware\SetLocale;
use App\Livewire\SystemNotificationsPopup;
use App\Support\Mail\Sender;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
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
            ->globalSearchKeyBindings(['mod+k'])
            ->viteTheme('resources/css/filament/admin/theme.css')
            // Menu order as specified (AD, section 1).
            ->navigationGroups(array_map(
                fn (string $key) => NavigationGroup::make()->label(fn (): string => (string) __('admin.nav.'.$key)),
                ['people', 'events', 'forms', 'finance', 'communication', 'automations', 'reports', 'privacy', 'settings', 'system'],
            ))
            ->renderHook(PanelsRenderHook::CONTENT_START, fn () => Sender::testAddress() !== null ? view('filament.test-mode', ['address' => Sender::testAddress()]) : '')
            ->renderHook(PanelsRenderHook::CONTENT_START, fn () => Livewire::mount(SystemNotificationsPopup::class))
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
