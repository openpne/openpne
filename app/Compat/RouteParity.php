<?php

namespace App\Compat;

/**
 * One OpenPNE 3 module's route parity. The subclass is the SSoT: it maps each ported
 * OpenPNE 3 route to an OpenPNE 4 route and records the routes intentionally not ported.
 *
 * Like the upgrade steps, this is typed PHP so the audit can bind it to the real Laravel
 * routes (a renamed/removed route fails CI) and to the OpenPNE 3 route inventory (an
 * un-ported route surfaces instead of being silently dropped).
 */
abstract class RouteParity
{
    protected string $module;

    public function module(): string
    {
        return $this->module;
    }

    /** @return list<RouteMap> */
    abstract public function maps(): array;

    /**
     * OpenPNE 3 routes intentionally not ported, with the reason.
     *
     * @return array<string, string> OpenPNE 3 route name => reason
     */
    public function gaps(): array
    {
        return [];
    }

    /** @return list<string> OpenPNE 3 route names covered by maps() */
    public function mappedRoutes(): array
    {
        return array_map(static fn (RouteMap $map): string => $map->op3Route, $this->maps());
    }
}
