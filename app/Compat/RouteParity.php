<?php

namespace App\Compat;

/**
 * One OpenPNE 3 module's route parity. The subclass is the SSoT: it maps each ported
 * OpenPNE 3 route to a Laravel route and records the routes intentionally not ported.
 *
 * Typed PHP so the audit can bind it to the real Laravel routes (a renamed/removed route
 * fails CI) and to the OpenPNE 3 route inventory (an un-ported route surfaces instead of
 * being silently dropped).
 */
abstract class RouteParity
{
    protected string $module;

    public function module(): string
    {
        return $this->module;
    }

    /**
     * The OpenPNE 3 module this parity binds to in the route inventory, or null when there is
     * no OpenPNE 3 counterpart (an OpenPNE 4-native feature like Block, whose OpenPNE 3 origin
     * was a member-config category, not a module). Inventory-bound audits skip a null module.
     */
    public function openpne3Module(): ?string
    {
        return $this->module;
    }

    /** @return list<RouteMap> */
    abstract public function maps(): array;

    /**
     * Per-screen surface-element inventory: the third parity axis (intra-screen content),
     * keyed by the OpenPNE 3 action whose template defines the screen. route parity says the
     * URL resolves; this says how much of that screen's content the Classic adapter renders.
     * Empty for a module not inventoried yet. A key must resolve through screenMap(), so the
     * command can render the screen's Laravel route and body id.
     *
     * @return array<string, list<ScreenElement>> screen key (see screenMap()) => surface elements
     */
    public function screens(): array
    {
        return [];
    }

    /**
     * The map a screens() key names. Three key shapes, so a screen never depends on maps()
     * declaration order: the OpenPNE 3 action alone (`deleteConfirm`) resolves within this
     * parity's own module; `module/action` (`diaryComment/deleteConfirm`) names a route whose
     * action lives in another OpenPNE 3 module (op3Module) and would otherwise collide with the
     * same-named action here; a Laravel route name (`group.members.pending`) pins one route when
     * several share an action within one module. A bare action that this module does not have
     * still reaches an op3Module route of that name (`history` → diaryComment), so a key only
     * needs the module prefix when the action exists on both sides.
     */
    public function screenMap(string $key): ?RouteMap
    {
        if (str_contains($key, '.')) {
            return $this->renderedMap($key);
        }

        [$module, $action] = str_contains($key, '/') ? explode('/', $key, 2) : [null, $key];

        foreach ([$module ?? $this->module, $module] as $wanted) {
            foreach ($this->maps() as $map) {
                if ($map->op3Action === $action && ($wanted === null || $this->moduleOf($map) === $wanted)) {
                    return $map;
                }
            }
        }

        return null;
    }

    /**
     * OpenPNE 3 routes intentionally not ported, with the reason.
     *
     * @return array<string, string> OpenPNE 3 route name => reason
     */
    public function gaps(): array
    {
        return [];
    }

    /**
     * OpenPNE 3 URLs preserved by a redirect to a canonical Laravel route rather than served
     * in place — the URL-compatibility contract for a URL whose OpenPNE 4 canonical moved
     * (e.g. /member/config?category=accessBlock -> block.list). Records the canonical↔legacy
     * relation the contract requires; the audit checks the target route exists.
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
     * The Classic shell layout letter (A/B/C) per Laravel route, for screens whose OpenPNE 3
     * layout is not the global default (layoutC). The letter is what OpenPNE 3 emitted as
     * `id="Layout…"` — chosen there by setLayout / view.yml / `decorate_with`, independent of
     * which zones have content. A/B require a sidemenu column (the skin floats `#Left` only under
     * A/B); list only the non-default screens, the rest fall back to C.
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
     * The Classic `<body id>` for a Laravel route, or null if it renders no `<body>`
     * (POST form submits). Derived from the mapped OpenPNE 3 action as OpenPNE 3 emitted it:
     * `page_{module}_{action}`.
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
     * The OpenPNE 3 module a Laravel route renders under — the same module the body id carries.
     * OpenPNE 3 keyed its per-module `config/view.yml` (stylesheets, layout) off it. Null when
     * this parity renders no page for the route.
     */
    public function moduleFor(string $laravelRoute): ?string
    {
        $map = $this->renderedMap($laravelRoute);

        return $map === null ? null : $this->moduleOf($map);
    }

    /** The first map rendering $laravelRoute as a page; POST submits (no op3Action) render none. */
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
