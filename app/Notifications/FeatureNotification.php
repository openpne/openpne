<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Support\Feature;

/**
 * A notification owned by an admin-togglable feature unit. Implementing this is the one place a
 * notification declares its unit; both the send gate (App\Notifications\Concerns\GatedByFeature)
 * and the feed's display filter (FeatureNotificationMap) read that single declaration.
 *
 * Static because the unit is a property of the class, not of an instance: the display side works
 * from the stored `notifications.type` string and has no notification to construct.
 */
interface FeatureNotification
{
    public static function feature(): Feature;
}
