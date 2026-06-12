<?php

namespace App\Upgrade\Steps;

use App\Compat\Openpne3Routes;
use App\Models\Navigation;
use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `navigation` → OpenPNE 4 `navigations`, normalizing `uri` to the URL form the Classic
 * renderer expects (App\Services\NavigationService) and keeping the original in `source_uri` for
 * DOM-id compatibility.
 *
 * OpenPNE 3 stored a navigation link as a route name (`@homepage`), an already-formed URL
 * (`/path`, `http(s)://…`), or a bare `module/action`. The normalization is a SQL CASE built from
 * the route inventory (openpne3-pc-frontend-routes.php) and the module/action map
 * (openpne3-module-actions.php):
 *
 *  - an already-formed URL is kept as-is;
 *  - a `@name` is looked up in the inventory (the name fixes the URL, so this is
 *    type-independent), keeping the `:id` placeholder the renderer threads the subject id into;
 *  - a bare `module/action` is type-aware — the friend/community contexts resolve to the
 *    id-bearing route, the others to the id-less route — because the same pair maps to different
 *    routes by context (e.g. diary/listMember → /diary/listMember vs /diary/listMember/:id).
 *
 * A value that matches nothing (an unported plugin's route, a custom action) is kept verbatim;
 * the renderer then treats it as unresolved and hides it. Only the five PC navigation types are
 * copied (see filter()); mobile/smartphone/backend rows are out of the Classic scope.
 */
class NavigationUpgrade extends UpgradeStep
{
    protected string $source = 'navigation';

    protected string $target = 'navigations';

    private readonly Openpne3Routes $routes;

    /** @var array<string, array{no_id?: string, with_id?: string}> */
    private readonly array $actions;

    public function __construct()
    {
        $this->routes = Openpne3Routes::default();
        $this->actions = require database_path('parity/openpne3-module-actions.php');
    }

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'type' => Column::source('type'),
            'uri' => Column::expr($this->uriExpr(), uses: ['uri', 'type']),
            'source_uri' => Column::source('uri'),
            'sort_order' => Column::source('sort_order'),
            'created_at' => Column::source('created_at'),
            'updated_at' => Column::source('updated_at'),
        ];
    }

    public function filter(): ?string
    {
        return sprintf('`type` IN (%s)', $this->typeList());
    }

    public function filterColumns(): array
    {
        return ['type'];
    }

    public function gaps(): array
    {
        return [
            'type (mobile_* / smartphone_* / backend_side rows)' => 'Out of scope: only the five PC navigation contexts ('.implode(', ', Navigation::TYPES).') are ported. The filter excludes the mobile, smartphone, and backend navigation types.',
        ];
    }

    private function typeList(): string
    {
        return implode(', ', array_map(static fn (string $t): string => "'{$t}'", Navigation::TYPES));
    }

    /** The normalization CASE over the source `uri`/`type`. */
    private function uriExpr(): string
    {
        $byName = $this->whenClauses($this->routeNameMap());
        $withId = $this->whenClauses($this->moduleActionMap(idBearing: true));
        $noId = $this->whenClauses($this->moduleActionMap(idBearing: false));

        return implode("\n", [
            'CASE',
            "    WHEN `uri` LIKE '/%' OR `uri` LIKE '%://%' THEN `uri`",
            "    WHEN `uri` LIKE '@%' THEN COALESCE(CASE `uri` {$byName} ELSE NULL END, `uri`)",
            "    WHEN `type` IN ('friend', 'community') THEN COALESCE(CASE `uri` {$withId} ELSE NULL END, `uri`)",
            "    ELSE COALESCE(CASE `uri` {$noId} ELSE NULL END, `uri`)",
            'END',
        ]);
    }

    /**
     * `@route_name` → URL, for every inventory route that is GET-reachable and has no param other
     * than `:id` (a multi-param or wildcard URL cannot be a static nav link).
     *
     * @return array<string, string>
     */
    private function routeNameMap(): array
    {
        $map = [];
        foreach ($this->routes->modules() as $module) {
            foreach ($this->routes->routeNames($module) as $name) {
                $url = $this->routes->url($module, $name);
                if ($url === null || ! $this->routes->isUrlCompatible($module, $name) || ! $this->isStaticUrl($url)) {
                    continue;
                }
                $map['@'.$name] = $url;
            }
        }

        return $map;
    }

    /**
     * `module/action` → URL. For the id-bearing contexts the id route is preferred (falling back to
     * the id-less one); for the id-less contexts only the id-less route is used.
     *
     * @return array<string, string>
     */
    private function moduleActionMap(bool $idBearing): array
    {
        $map = [];
        foreach ($this->actions as $pair => $routes) {
            $name = $idBearing ? ($routes['with_id'] ?? $routes['no_id'] ?? null) : ($routes['no_id'] ?? null);
            $url = $name !== null ? $this->routes->urlByName($name) : null;
            if ($url !== null) {
                $map[$pair] = $url;
            }
        }

        return $map;
    }

    /** A URL usable as a static link: no wildcard and no param other than `:id`. */
    private function isStaticUrl(string $url): bool
    {
        $withoutId = str_replace(':id', '', $url);

        return ! str_contains($withoutId, ':') && ! str_contains($withoutId, '*');
    }

    /** @param array<string, string> $map */
    private function whenClauses(array $map): string
    {
        $parts = [];
        foreach ($map as $key => $url) {
            $parts[] = sprintf("WHEN '%s' THEN '%s'", $this->escape($key), $this->escape($url));
        }

        return implode(' ', $parts);
    }

    private function escape(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
