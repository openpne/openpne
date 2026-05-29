<?php

namespace App\Upgrade;

use App\Upgrade\Steps\DiaryUpgrade;
use App\Upgrade\Steps\FriendRequestUpgrade;
use App\Upgrade\Steps\FriendshipUpgrade;
use App\Upgrade\Steps\MemberBlockUpgrade;

/** The upgrade steps in run order. Adding a feature = adding its step here. */
final class StepRegistry
{
    /** @return list<class-string<UpgradeStep>> */
    public static function classes(): array
    {
        return [
            FriendshipUpgrade::class,
            FriendRequestUpgrade::class,
            MemberBlockUpgrade::class,
            DiaryUpgrade::class,
        ];
    }

    /** @return list<UpgradeStep> */
    public static function all(): array
    {
        return array_map(static fn (string $class): UpgradeStep => new $class, self::classes());
    }
}
