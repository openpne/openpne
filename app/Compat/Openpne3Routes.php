<?php

namespace App\Compat;

use RuntimeException;

/** Reads the OpenPNE 3 route inventory fixture (database/parity/openpne3-pc-frontend-routes.php). */
final class Openpne3Routes
{
    /** @param array<string, array{disables_global_fallback?: bool, routes: array<string, array{0: string, 1: string}>}> $data */
    public function __construct(private readonly array $data) {}

    public static function default(): self
    {
        return new self(require database_path('parity/openpne3-pc-frontend-routes.php'));
    }

    /** @return list<string> named route names of the module */
    public function routeNames(string $module): array
    {
        return array_keys($this->module($module)['routes']);
    }

    public function url(string $module, string $route): ?string
    {
        return $this->module($module)['routes'][$route][0] ?? null;
    }

    /** The route's sf_method constraint: 'POST' or 'ANY' (unconstrained). */
    public function method(string $module, string $route): ?string
    {
        return $this->module($module)['routes'][$route][1] ?? null;
    }

    /**
     * Whether the route is reachable by GET, so its URL can be bookmarked / mailed / linked
     * and carries a URL-preservation obligation. POST-only routes (form submits) are not.
     */
    public function isUrlCompatible(string $module, string $route): bool
    {
        return $this->method($module, $route) !== 'POST';
    }

    /**
     * Whether the module disables OpenPNE 3's global deprecated fallback (/:module/:action/*).
     * When true, the named routes are the complete set of reachable URLs, so the coverage
     * audit over named routes is exhaustive; when false, un-named actions stay reachable.
     */
    public function disablesGlobalFallback(string $module): bool
    {
        return $this->module($module)['disables_global_fallback'] ?? false;
    }

    /** @return array{disables_global_fallback?: bool, routes: array<string, array{0: string, 1: string}>} */
    private function module(string $module): array
    {
        return $this->data[$module] ?? throw new RuntimeException("Module `{$module}` not in the OpenPNE 3 route inventory");
    }
}
