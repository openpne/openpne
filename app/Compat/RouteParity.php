<?php

namespace App\Compat;

abstract class RouteParity
{
    protected string $module;

    public function module(): string
    {
        return $this->module;
    }

    /**
     * The OpenPNE 3 module this parity binds to in the route inventory, or null for an
     * OpenPNE 4-native feature, which the inventory-bound audits skip.
     */
    public function openpne3Module(): ?string
    {
        return $this->module;
    }

    /** @return list<RouteMap> */
    abstract public function maps(): array;

    /**
     * Every key must resolve through screenMap(), which yields the screen's route and body id.
     *
     * @return array<string, list<ScreenElement>> screen key (see screenMap()) => surface elements
     */
    public function screens(): array
    {
        return [];
    }

    /**
     * Resolves a screens() key — an OpenPNE 3 action, `module/action` for a route in another
     * OpenPNE 3 module, or a Laravel route name (tried first) — to its GET map. POST submits are
     * excluded even when they carry the op3Action of the page they re-render.
     */
    public function screenMap(string $key): ?RouteMap
    {
        $screens = array_values(array_filter(
            $this->maps(),
            static fn (RouteMap $map): bool => $map->method === 'GET' && $map->op3Action !== null,
        ));

        foreach ($screens as $map) {
            if ($map->laravelRoute === $key) {
                return $map;
            }
        }

        [$module, $action] = str_contains($key, '/') ? explode('/', $key, 2) : [null, $key];

        foreach ([$module ?? $this->module, $module] as $wanted) {
            foreach ($screens as $map) {
                if ($map->op3Action === $action && ($wanted === null || $this->moduleOf($map) === $wanted)) {
                    return $map;
                }
            }
        }

        return null;
    }

    /**
     * OpenPNE 3 routes this parity declares out of scope, with the reason.
     *
     * @return array<string, string> OpenPNE 3 route name => reason
     */
    public function gaps(): array
    {
        return [];
    }

    /**
     * OpenPNE 3 URLs kept reachable by a redirect to a canonical Laravel route rather than served
     * in place; each target must be a registered route.
     *
     * @return array<string, string> legacy OpenPNE 3 URL => canonical Laravel route name
     */
    public function compatRedirects(): array
    {
        return [];
    }

    /** @return list<string> named OpenPNE 3 route names covered by maps() (fallback-only / native maps excluded) */
    public function mappedRoutes(): array
    {
        return array_values(array_filter(
            array_map(static fn (RouteMap $map): ?string => $map->op3Route, $this->maps()),
        ));
    }

    /**
     * Whether the module leaves OpenPNE 3's global /:module/:action fallback on, so its named
     * routes are not the complete set of reachable URLs. Returning true consciously accepts
     * non-exhaustive named-route coverage: the compatibility-relevant routes are mapped and
     * fallback-only actions are handled per route.
     */
    public function acknowledgesGlobalFallback(): bool
    {
        return false;
    }

    /**
     * The letter OpenPNE 3 declared (setLayout / view.yml / decorate_with) for each screen whose
     * layout is not the global default C. A sidemenu column requires A or B, since the skin
     * floats `#Left` only under those.
     *
     * @return array<string, string> laravelRoute => letter
     */
    protected function layouts(): array
    {
        return [];
    }

    /** The Classic layout letter for a Laravel route, or null when it uses the global default (C). */
    public function layout(string $laravelRoute): ?string
    {
        return $this->layouts()[$laravelRoute] ?? null;
    }

    /**
     * The Classic `<body id>` for a Laravel route, or null when it renders no `<body>`. Derived from
     * the mapped OpenPNE 3 action as OpenPNE 3 emitted it: `page_{module}_{action}`.
     */
    public function bodyId(string $laravelRoute): ?string
    {
        $map = $this->renderedMap($laravelRoute);

        if ($map === null) {
            return null;
        }

        return "page_{$this->moduleOf($map)}_{$map->op3Action}";
    }

    /**
     * The OpenPNE 3 module a Laravel route renders under (the body id's module), or null when
     * this parity renders no page for it.
     */
    public function moduleFor(string $laravelRoute): ?string
    {
        $map = $this->renderedMap($laravelRoute);

        return $map === null ? null : $this->moduleOf($map);
    }

    /** The first map carrying an op3Action for $laravelRoute; a POST submit may carry the one of the page it re-renders. */
    private function renderedMap(string $laravelRoute): ?RouteMap
    {
        foreach ($this->maps() as $map) {
            if ($map->laravelRoute === $laravelRoute && $map->op3Action !== null) {
                return $map;
            }
        }

        return null;
    }

    private function moduleOf(RouteMap $map): string
    {
        return $map->op3Module ?? $this->module;
    }
}
