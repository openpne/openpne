<?php

declare(strict_types=1);

namespace App\Features\Home;

use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;

/**
 * Which page the Modern home renders. The unified layout is an experiment an operator opts into per
 * site: HomeController keeps the same route and reads the same sources either way, so switching it
 * back off restores the shipped dashboard without a deploy.
 */
final class HomeLayout
{
    public static function unifiedEnabled(): bool
    {
        return (bool) app(SnsSettingService::class)->get(SnsSettingKey::ModernUnifiedHome);
    }
}
