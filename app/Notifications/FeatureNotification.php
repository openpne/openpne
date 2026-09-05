<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Support\Feature;

/**
 * The one place a notification declares its feature unit; the send gate and the feed's display filter both
 * read it. Static because the display side works from the stored `notifications.type` string and has no
 * notification to construct.
 */
interface FeatureNotification
{
    public static function feature(): Feature;
}
