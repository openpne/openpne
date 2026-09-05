<?php

declare(strict_types=1);

namespace App\Notifications\Settings;

/**
 * Web gates the Laravel `database` record, Mail the notification email
 * (docs/internals/notifications.md, "The per-member catalog").
 */
enum NotificationChannel: string
{
    case Web = 'web';
    case Mail = 'mail';
}
