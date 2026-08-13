<?php

namespace App\Compat;

use App\Compat\Parities\AuthRouteParity;
use App\Compat\Parities\BlockRouteParity;
use App\Compat\Parities\CommunityEventRouteParity;
use App\Compat\Parities\CommunityRouteParity;
use App\Compat\Parities\CommunityTopicRouteParity;
use App\Compat\Parities\DiaryRouteParity;
use App\Compat\Parities\DirectMessageRouteParity;
use App\Compat\Parities\FriendRouteParity;
use App\Compat\Parities\MemberRouteParity;
use App\Compat\Parities\PolicyRouteParity;
use App\Compat\Parities\TimelineRouteParity;

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
            DirectMessageRouteParity::class,
            TimelineRouteParity::class,
            AuthRouteParity::class,
            PolicyRouteParity::class,
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

    /** The OpenPNE 3 module a Laravel route renders under, across all parities, or null if none does. */
    public static function module(string $laravelRoute): ?string
    {
        foreach (self::all() as $parity) {
            $module = $parity->moduleFor($laravelRoute);
            if ($module !== null) {
                return $module;
            }
        }

        return null;
    }

    /** The Classic layout letter for a Laravel route across all parities, or null for the default (C). */
    public static function layout(string $laravelRoute): ?string
    {
        foreach (self::all() as $parity) {
            $letter = $parity->layout($laravelRoute);
            if ($letter !== null) {
                return $letter;
            }
        }

        return null;
    }
}
