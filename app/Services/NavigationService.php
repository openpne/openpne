<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Navigation;
use App\Support\NavigationUri;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves the admin-configurable Classic navigation (`navigations`) into render-ready link lists.
 *
 * The stored rows (grouped by type, with raw captions) are cached as plain arrays — never Eloquent
 * models, since the production cache store does not allow serializing classes. The admin UI calls
 * clearCache() after a change. Per-request resolution (route existence, %term% captions, subject
 * id) is NOT cached: route sets change between deploys, so a route-existence result must not
 * outlive the request.
 */
class NavigationService
{
    private const CACHE_KEY = 'navigations';

    private const CACHE_TTL = 3600;

    /**
     * Route names that resolve but are not real pages (OpenPNE 3 compatibility shims), so a nav
     * item pointing at them is hidden rather than linking to a 404/redirect.
     */
    private const SHIM_ROUTES = ['member.config.access_block_compat'];

    /** Per-request memo of internal-path existence, keyed by path. */
    private array $pathExists = [];

    private ?string $logoutPath = null;

    /**
     * Render-ready items for a navigation type, in sort order. Each item is
     * ['href' => string, 'label' => string, 'domId' => string, 'isPostLogout' => bool].
     * Unresolved uris, items pointing at missing routes, and compatibility shims are dropped.
     *
     * @return list<array{href: string, label: string, domId: string, isPostLogout: bool}>
     */
    public function visibleEntries(string $type, string $locale, ?int $subjectId = null): array
    {
        $prefix = in_array($type, Navigation::GLOBAL_TYPES, true) ? 'globalNav' : $type;
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
            if (str_contains($href, ':id') || ! $this->internalPathExists($href)) {
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

    /** Thread the context subject id into a `:id` slot, else append `?id=N` (OpenPNE 3 behaviour). */
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

    /** Whether a GET request for this path matches a real (non-shim) route. */
    private function internalPathExists(string $href): bool
    {
        $path = parse_url($href, PHP_URL_PATH) ?: '/';

        return $this->pathExists[$path] ??= $this->matchesRealRoute($path);
    }

    private function matchesRealRoute(string $path): bool
    {
        // Route::matches() validates uri/method without binding parameters, so this never mutates
        // the routes (the current page's route is a shared instance). Only GET routes count: a
        // POST-only path is not a navigable link (logout, the one exception, is handled earlier).
        $request = Request::create($path, 'GET');
        foreach (Route::getRoutes()->getRoutesByMethod()['GET'] ?? [] as $route) {
            if ($route->matches($request)) {
                return ! in_array($route->getName(), self::SHIM_ROUTES, true);
            }
        }

        return false;
    }
}
