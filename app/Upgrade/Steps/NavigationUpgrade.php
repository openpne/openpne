<?php

namespace App\Upgrade\Steps;

use App\Compat\Openpne3Routes;
use App\Models\Navigation;
use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `navigation` → OpenPNE 4 `navigations`, normalizing `uri` to the URL the Classic renderer
 * expects and keeping the original in `source_uri` for DOM-id compatibility. An unresolved value
 * stays verbatim for the renderer to hide, `community` lands as `group`, and RENAMED_URLS points a
 * moved route at its OpenPNE 4 canonical rather than a redirect.
 */
class NavigationUpgrade extends UpgradeStep
{
    protected string $source = 'navigation';

    protected string $target = 'navigations';

    /** OpenPNE 4 navigation type => the OpenPNE 3 `navigation.type` it is copied from. */
    private const SOURCE_TYPES = ['group' => 'community'];

    /**
     * Inventory URLs whose OpenPNE 4 canonical moved; the normalization would otherwise emit the
     * OpenPNE 3 URL, now only a redirect. `:id` marks where the renderer threads the context id in,
     * where OpenPNE 3 spelled some of these as a bare path plus `?id=`.
     */
    private const RENAMED_URLS = [
        '/userAgreement' => '/terms',
        '/default/userAgreement' => '/terms',
        '/privacyPolicy' => '/privacy',
        '/default/privacyPolicy' => '/privacy',
        '/community/:id' => '/groups/:id',
        '/community/search' => '/groups',
        '/community/joinList' => '/groups/mine',
        '/community/edit' => '/groups/edit',
        '/community/join' => '/groups/:id/join',
        '/community/quit' => '/groups/:id/quit',
        '/community/delete/:id' => '/groups/:id/delete',
        '/community/member/list' => '/groups/:id/members',
        '/community/member/manage/:id' => '/groups/:id/members/manage',
        '/communityTopic/listCommunity/:id' => '/groups/:id/topics',
        '/communityTopic/new/:id' => '/groups/:id/topics/new',
        '/communityEvent/listCommunity/:id' => '/groups/:id/events',
        '/communityEvent/new/:id' => '/groups/:id/events/new',
    ];

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
            'type' => Column::expr($this->typeExpr(), uses: ['type']),
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
            'type (mobile_* / smartphone_* / backend_side rows)' => 'Out of scope: only the five PC navigation contexts ('.implode(', ', self::sourceTypes()).') are ported. The filter excludes the mobile, smartphone, and backend navigation types.',
        ];
    }

    private function typeList(): string
    {
        return implode(', ', array_map(static fn (string $t): string => "'{$t}'", self::sourceTypes()));
    }

    /** The OpenPNE 3 `navigation.type` values this step copies, in Navigation::TYPES order. */
    private static function sourceTypes(): array
    {
        return array_map(static fn (string $t): string => self::SOURCE_TYPES[$t] ?? $t, Navigation::TYPES);
    }

    /** The source type mapped onto the OpenPNE 4 vocabulary; an unrenamed type passes through. */
    private function typeExpr(): string
    {
        $whens = [];
        foreach (self::SOURCE_TYPES as $target => $source) {
            $whens[] = sprintf("WHEN '%s' THEN '%s'", $source, $target);
        }

        return 'CASE `type` '.implode(' ', $whens).' ELSE `type` END';
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
                $map['@'.$name] = self::RENAMED_URLS[$url] ?? $url;
            }
        }

        return $map;
    }

    /**
     * `module/action` → URL, id-bearing contexts preferring the id route (falling back to the id-less
     * one) because the same pair maps to different routes by context, and id-less contexts using only
     * the id-less route. A value beginning with `/` is a literal URL (an action with no named OpenPNE 3
     * route); any other is an inventory route name.
     *
     * @return array<string, string>
     */
    private function moduleActionMap(bool $idBearing): array
    {
        $map = [];
        foreach ($this->actions as $pair => $routes) {
            $name = $idBearing ? ($routes['with_id'] ?? $routes['no_id'] ?? null) : ($routes['no_id'] ?? null);
            if ($name === null) {
                continue;
            }
            $url = str_starts_with($name, '/') ? $name : $this->routes->urlByName($name);
            if ($url !== null) {
                $map[$pair] = self::RENAMED_URLS[$url] ?? $url;
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
