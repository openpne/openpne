<?php

declare(strict_types=1);

namespace App\Features\Notifications;

/**
 * OpenPNE 3's three compartments in badge order; everything the center shows is classified here and
 * nowhere else, so a badge can never count an event its panel does not list.
 */
enum NotificationCenterCategory: string
{
    case DirectMessage = 'direct_message';
    case Friend = 'friend';
    case Other = 'other';

    public static function for(?string $kind): self
    {
        return match ($kind) {
            'direct_message_received' => self::DirectMessage,
            'friend_requested' => self::Friend,
            default => self::Other,
        };
    }

    public function badgeId(): string
    {
        return match ($this) {
            self::DirectMessage => 'nc_icon1',
            self::Friend => 'nc_icon2',
            self::Other => 'nc_icon3',
        };
    }
}
