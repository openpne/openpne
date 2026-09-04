<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Middleware\EnsureFeatureEnabled;
use App\Models\Navigation;
use App\Support\Feature;
use App\Support\NavigationUri;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * Rows are cached as plain arrays because the production cache store cannot serialize classes. Route
 * matching, feature toggles and %term% captions are resolved per request and never cached, so the
 * rows stay feature-agnostic and switching a unit needs no cache clear.
 */
class NavigationService
{
    private const CACHE_KEY = 'navigations';

    private const CACHE_TTL = 3600;

    /**
     * Route names that resolve but are not real pages (OpenPNE 3 compatibility shims), so a nav
     * item pointing at them is hidden rather than linking to a redirect; empty is a valid state.
     *
     * @var list<string>
     */
    private const SHIM_ROUTES = [];

    /**
     * Per-request memo of the GET route an internal path resolves to (null = none), keyed by path.
     *
     * @var array<string, ?RoutingRoute>
     */
    private array $pathRoutes = [];

    private ?string $logoutPath = null;

    /** @return list<array{href: string, label: string, domId: string, isPostLogout: bool}> */
    public function visibleEntries(string $type, string $locale, ?int $subjectId = null): array
    {
        // Local-nav ids carry the presentation token, not the stored type: `group` renders as
        // OpenPNE 3's `community` so a site's custom CSS keeps matching.
        $prefix = in_array($type, Navigation::GLOBAL_TYPES, true) ? 'globalNav' : Navigation::presentationToken($type);
        $lang = Navigation::translationLang($locale);
        $terms = app(TermService::class);

        $items = [];
        foreach ($this->grouped()[$type] ?? [] as $row) {
            $uri = $row['uri'];
            if (! NavigationUri::isRenderable($uri)) {
                continue;
            }

            $domId = $prefix.'_'.Navigation::slug($row['source_uri'] ?? $uri);
            $label = $terms->replace($this->caption($row['captions'], $lang), $locale);

            // An external http(s) URL is kept verbatim — checked before the logout case so an external
            // URL whose path happens to be /logout stays a plain link, not a CSRF POST form.
            if (NavigationUri::isExternal($uri)) {
                $items[] = ['href' => $uri, 'label' => $label, 'domId' => $domId, 'isPostLogout' => false];

                continue;
            }

            // Internal links render absolute (url()), matching every other Classic link.
            if ($this->isLogout($uri)) {
                $items[] = ['href' => url($uri), 'label' => $label, 'domId' => $domId, 'isPostLogout' => true];

                continue;
            }

            $href = $this->applySubject($uri, $subjectId);
            // A `:id` link with no subject in this context (e.g. an upgraded `@member_profile` that
            // landed in a global type) cannot be resolved — hide it rather than link to literal :id.
            if (str_contains($href, ':id') || ! $this->internalPathIsLinkable($href)) {
                continue;
            }

            $items[] = ['href' => url($href), 'label' => $label, 'domId' => $domId, 'isPostLogout' => false];
        }

        return $items;
    }

    /** Drop the cached nav rows. Call after persisting changes from the admin UI. */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Nav rows grouped by type as plain arrays, cached. Each row keeps the raw caption per lang so
     * %term% resolution stays at render time (term overrides change independently of nav rows).
     *
     * @return array<string, list<array{uri: string, source_uri: ?string, captions: array<string, string>}>>
     */
    private function grouped(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function (): array {
            if (! Schema::hasTable('navigations')) {
                return [];
            }

            $captions = DB::table('navigation_translations')
                ->get()
                ->groupBy('id')
                ->map(fn ($rows) => $rows->pluck('caption', 'lang')->all());

            return DB::table('navigations')
                ->orderByRaw('sort_order IS NULL, sort_order')
                ->get()
                ->groupBy('type')
                ->map(fn ($rows) => $rows->map(fn ($row) => [
                    'uri' => $row->uri,
                    'source_uri' => $row->source_uri,
                    'captions' => $captions[$row->id] ?? [],
                ])->values()->all())
                ->all();
        });
    }

    /**
     * The caption for the requested translation lang, falling back to en, then ja_JP, then any
     * present translation — so a row localised in only one language is never an empty label.
     *
     * @param  array<string, string>  $captions
     */
    private function caption(array $captions, string $lang): string
    {
        return $captions[$lang] ?? $captions['en'] ?? $captions['ja_JP'] ?? (string) (reset($captions) ?: '');
    }

    /** Thread the context subject id into a `:id` slot, else append `?id=N` (OpenPNE 3 behavior). */
    private function applySubject(string $uri, ?int $subjectId): string
    {
        if ($subjectId === null) {
            return $uri;
        }

        if (str_contains($uri, ':id')) {
            return str_replace(':id', (string) $subjectId, $uri);
        }

        return $uri.(str_contains($uri, '?') ? '&' : '?').'id='.$subjectId;
    }

    private function isLogout(string $uri): bool
    {
        $path = parse_url($uri, PHP_URL_PATH);

        return is_string($path) && trim($path, '/') === $this->logoutPath();
    }

    private function logoutPath(): string
    {
        return $this->logoutPath ??= Route::getRoutes()->getByName('logout')?->uri() ?? 'logout';
    }

    /** Whether a GET request for this path reaches a page a member can actually open. */
    private function internalPathIsLinkable(string $href): bool
    {
        $path = parse_url($href, PHP_URL_PATH) ?: '/';

        if (! array_key_exists($path, $this->pathRoutes)) {
            $this->pathRoutes[$path] = $this->matchRealRoute($path);
        }

        $route = $this->pathRoutes[$path];

        return $route !== null && $this->featureEnabled($route);
    }

    private function matchRealRoute(string $path): ?RoutingRoute
    {
        // Route::matches() binds nothing, so the shared current route is never mutated; the fallback
        // route is skipped or every path would look reachable.
        $request = Request::create($path, 'GET');
        foreach (Route::getRoutes()->getRoutesByMethod()['GET'] ?? [] as $route) {
            if (! $route->isFallback && $route->matches($request)) {
                return in_array($route->getName(), self::SHIM_ROUTES, true) ? null : $route;
            }
        }

        return null;
    }

    /**
     * Whether every feature unit gating the matched route is on. Ownership is read off the route's
     * own EnsureFeatureEnabled wiring, so an unnamed alias is covered and there is no second list
     * of route names to keep in step with routes/web.php.
     */
    private function featureEnabled(RoutingRoute $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware) || ! str_starts_with($middleware, EnsureFeatureEnabled::class.':')) {
                continue;
            }

            $feature = Feature::tryFrom(substr($middleware, strlen(EnsureFeatureEnabled::class) + 1));
            if ($feature?->enabled() === false) {
                return false;
            }
        }

        return true;
    }
}
