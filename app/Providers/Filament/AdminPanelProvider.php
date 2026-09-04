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
use Filament\Schemas\Schema;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
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
            // Closure: panel() boots on every console command, migrate included, before the settings
            // table sns_name() reads exists.
            ->brandName(fn (): string => sns_name().' '.__('Admin panel'))
            // Closure for the same reason as brandName.
            ->favicon(fn (): string => brand_favicon_url() ?? asset('favicon-32x32.png'))
            // Separate `admin` guard, entirely independent of the member-facing
            // guard: a logged-in member is never treated as an administrator
            // and vice versa.
            ->authGuard('admin')
            ->login(Login::class)
            // Opt-in (isRequired false) by design, and codeWindow(1) tightens Filament's default of 8
            // (docs/internals/security.md).
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
            // Labels are closures: a bare __() evaluates at boot, and a group whose label mismatches its
            // screens' getNavigationGroup() silently drops to the end.
            ->navigationGroups([
                NavigationGroup::make(fn (): string => __('Members')),
                NavigationGroup::make(fn (): string => __('Content')),
                NavigationGroup::make(fn (): string => __('Settings')),
                NavigationGroup::make(fn (): string => __('Appearance (Classic)')),
                NavigationGroup::make(fn (): string => __('System')),
            ])
            ->middleware([
                // Outermost so every response gets the headers (a 419, an auth redirect), safe there
                // because it only sets static headers, and listed at all because the panel does not
                // inherit the `web` group.
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
                // After StartSession, and scoped to `:session` so an admin page never picks up a
                // co-logged-in member's persisted locale.
                SetLocale::class.':session',
            ])
            // Posts to the session-only locale route so a co-logged-in member's persisted locale is
            // untouched.
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

    /**
     * Filament's panel-wide defaults, so a screen added later inherits the admin date format
     * (docs/internals/datetime.md).
     */
    public function boot(): void
    {
        Table::configureUsing(function (Table $table): void {
            $table
                ->defaultDateDisplayFormat('Y-m-d')
                ->defaultDateTimeDisplayFormat('Y-m-d H:i')
                ->defaultTimeDisplayFormat('H:i');
        });

        // Infolist entries read their defaults from Schema; DateTimePicker carries its own and is not
        // covered.
        Schema::configureUsing(function (Schema $schema): void {
            $schema
                ->defaultDateDisplayFormat('Y-m-d')
                ->defaultDateTimeDisplayFormat('Y-m-d H:i')
                ->defaultTimeDisplayFormat('H:i');
        });
    }
}
