<?php

namespace App\Providers\Filament;

use App\Auth\AdminAppAuthentication;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\SecuritySettings;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
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
            // Closure, not eager: sns_name() reads the DB, and panel() boots on every console
            // command (migrate included) where the settings table may not exist yet. Filament
            // evaluates the brand name at render, so the DB read happens only per request.
            // The suffix marks the realm in the header and every browser title, so an admin tab
            // is never mistaken for the member surface; keeping the site name in front keeps
            // tabs of different installs distinguishable from each other.
            ->brandName(fn (): string => sns_name().' '.__('Admin panel'))
            // Browsers auto-request /favicon.ico; this makes Filament emit an explicit <link> so the
            // admin tab shows the site's mark on the PNG path too — kept per-site so tabs of
            // different installs stay distinguishable. Closure for the same reason as brandName above.
            ->favicon(fn (): string => brand_favicon_url() ?? asset('favicon-32x32.png'))
            // Separate `admin` guard, entirely independent of the member-facing
            // guard: a logged-in member is never treated as an administrator
            // and vice versa.
            ->authGuard('admin')
            ->login(Login::class)
            // Opt-in TOTP two-factor auth (Filament's built-in App provider). isRequired is
            // false by design — a nudge, not a gate (see the dashboard reminder widget and
            // docs/internals/security.md). codeWindow(1) tightens Filament's lax
            // default (8 ≈ ±4 min) to ±1 step (~±30s). AdminAppAuthentication revokes other
            // sessions on enable/disable.
            ->multiFactorAuthentication(
                [AdminAppAuthentication::make()->recoverable()->codeWindow(1)],
                isRequired: false,
            )
            // The Security page (own-account 2FA) lives in the avatar menu, next to the theme
            // switch — where an operator looks for their own account settings — not the sidebar.
            ->userMenuItems([
                MenuItem::make()
                    ->label(__('Security'))
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->url(fn (): string => SecuritySettings::getUrl()),
            ])
            // Deliberately not brand_color(): the member surface follows the per-site brand
            // color, while the admin panel keeps a fixed accent of its own — one more cue,
            // besides the brand-name suffix, that separates the two realms at a glance.
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            // Only our own dashboard widgets are discovered; Filament's default Account/Info cards
            // are intentionally not registered (logout stays in the top-right user menu).
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            // Explicit group order. Labels are closures so they resolve in the request locale (matching
            // each screen's getNavigationGroup()); a bare __() here would evaluate at boot and a locale
            // mismatch would silently drop a group to the end.
            ->navigationGroups([
                NavigationGroup::make(fn (): string => __('Content')),
                NavigationGroup::make(fn (): string => __('Settings')),
                NavigationGroup::make(fn (): string => __('Appearance (Classic)')),
                NavigationGroup::make(fn (): string => __('Master Data')),
            ])
            ->middleware([
                // Outermost so it decorates every response the inner stack produces — not just a
                // rendered page but a CSRF 419, an auth redirect, a binding error. The panel does
                // NOT inherit the `web` group, so without this the admin pages — the highest-value
                // clickjacking target — would ship no security headers. (Livewire endpoints already
                // run under the `web` group.) It only sets static headers, so the early slot is safe.
                SecurityHeaders::class,
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
            // Shared client-side image lightbox; thumbnails dispatch `open-image-lightbox` to it.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): View => view('filament.components.image-lightbox'),
            )
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
