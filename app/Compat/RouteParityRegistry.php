<?php

namespace App\Compat;

use App\Compat\Parities\AuthRouteParity;
use App\Compat\Parities\BlockRouteParity;
use App\Compat\Parities\CommunityEventRouteParity;
use App\Compat\Parities\CommunityRouteParity;
use App\Compat\Parities\CommunityTopicRouteParity;
use App\Compat\Parities\DiaryRouteParity;
use App\Compat\Parities\FriendRouteParity;
use App\Compat\Parities\MemberRouteParity;
use App\Compat\Parities\MessageRouteParity;

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
            CommunityRouteParity::class,
            CommunityTopicRouteParity::class,
            CommunityEventRouteParity::class,
            MessageRouteParity::class,
            AuthRouteParity::class,
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
