<?php

namespace App\Compat;

use App\Compat\Parities\DiaryRouteParity;

/** The route parities. Adding a feature's Classic adapter = adding its parity here. */
final class RouteParityRegistry
{
    /** @return list<class-string<RouteParity>> */
    public static function classes(): array
    {
        return [
            DiaryRouteParity::class,
        ];
    }

    /** @return list<RouteParity> */
    public static function all(): array
    {
        return array_map(static fn (string $class): RouteParity => new $class, self::classes());
    }
}
