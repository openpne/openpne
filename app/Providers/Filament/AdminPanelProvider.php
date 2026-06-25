<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
use App\Http\Middleware\SetLocale;
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
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            // Separate `admin` guard, entirely independent of the member-facing
            // guard: a logged-in member is never treated as an administrator
            // and vice versa.
            ->authGuard('admin')
            ->login(Login::class)
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            // Explicit group order. Labels are closures so they resolve in the request locale (matching
            // each screen's getNavigationGroup()); a bare __() here would evaluate at boot and a locale
            // mismatch would silently drop a group to the end.
            ->navigationGroups([
                NavigationGroup::make(fn (): string => __('Settings')),
                NavigationGroup::make(fn (): string => __('Appearance')),
                NavigationGroup::make(fn (): string => __('Master Data')),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                // SetLocale runs after StartSession so it can read session('locale').
                // Required because the Filament panel does NOT inherit the `web` middleware
                // group — the panel keeps its own stack and must register SetLocale here too,
                // otherwise admin pages would always render in APP_LOCALE regardless of the
                // user's session preference. `:session` scope keeps it admin-correct: an admin
                // page must not pick up a co-logged-in member's persisted members.locale.
                SetLocale::class.':session',
            ])
            // ja↔en toggle in the panel header and on the login screen. Posts to the
            // session-only locale route so a co-logged-in member's persisted locale is untouched.
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_AFTER,
                fn (): View => view('filament.locale-switcher'),
            )
            ->renderHook(
                PanelsRenderHook::SIMPLE_LAYOUT_START,
                fn (): View => view('filament.login-locale-switcher'),
                scopes: [Login::class],
            )
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
