<?php

declare(strict_types=1);

namespace App\Notifications\Settings;

/**
 * The two member-facing delivery channels of the notification catalog (the web/mail axes of the
 * imported OpenPNE 3 is_send_* keys). Web gates the in-app per-event record (the Laravel
 * 'database' notification channel feeding the notification feed); Mail gates the notification
 * email.
 */
enum NotificationChannel: string
{
    case Web = 'web';
    case Mail = 'mail';
}
