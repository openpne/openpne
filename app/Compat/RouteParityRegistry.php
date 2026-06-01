<?php

namespace App\Compat;

use App\Compat\Parities\BlockRouteParity;
use App\Compat\Parities\DiaryRouteParity;
use App\Compat\Parities\FriendRouteParity;
use App\Compat\Parities\MemberRouteParity;

/** The route parities. Adding a feature's Classic adapter = adding its parity here. */
final class RouteParityRegistry
{
    /** @return list<class-string<RouteParity>> */
    public static function classes(): array
    {
        return [
            DiaryRouteParity::class,
            FriendRouteParity::class,
            BlockRouteParity::class,
            MemberRouteParity::class,
        ];
    }

    /** @return list<RouteParity> */
    public static function all(): array
    {
        return array_map(static fn (string $class): RouteParity => new $class, self::classes());
    }

    /** The Classic `<body id>` for a Laravel route across all parities, or null if none derives one. */
    public static function bodyId(string $laravelRoute): ?string
    {
        foreach (self::all() as $parity) {
            $bodyId = $parity->bodyId($laravelRoute);
            if ($bodyId !== null) {
                return $bodyId;
            }
        }

        return null;
    }
}
