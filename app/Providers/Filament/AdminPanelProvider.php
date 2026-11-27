<?php

namespace App\Providers\Filament;

use App\Filament\Auth\LogoutResponse;
use App\Filament\Support\InitialsAvatarProvider;
use App\Files\SiteIcon;
use App\Http\Middleware\EnsureAdministrator;
use App\Http\Middleware\PreventRequestForgery;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Filament\Auth\Http\Responses\Contracts\LogoutResponse as LogoutResponseContract;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Schemas\Schema;
use Filament\Support\Colors\Color;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function register(): void
    {
        parent::register();

        $this->app->bind(LogoutResponseContract::class, LogoutResponse::class);
    }

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
            ->favicon(fn (): ?string => app(SiteIcon::class)->faviconUrl())
            // No login page of its own: a guest is sent to the member login, and the member's
            // administrator role opens the panel (docs/internals/administrators.md).
            ->authGuard('member')
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            // Deliberately not brand_color(): the member realm follows the per-site brand
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
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                EnsureAdministrator::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                SetLocale::class,
            ])
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_AFTER,
                fn (): View => view('filament.locale-switcher'),
            )
            // Shared client-side image lightbox; thumbnails dispatch `open-image-lightbox` to it.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): View => view('filament.components.image-lightbox'),
            )
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): View => view('filament.site-icon-raster'),
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
