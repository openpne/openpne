<?php

declare(strict_types=1);

namespace App\Features\Notifications;

/**
 * The three compartments OpenPNE 3's notification centre split its events into — one per badge on
 * the sprite, in that order. Everything the centre shows is classified here and nowhere else, so a
 * badge can never count an event its panel does not list, or count one twice.
 */
enum NotificationCenterCategory: string
{
    case Message = 'message';
    case Friend = 'friend';
    case Other = 'other';

    public static function for(?string $kind): self
    {
        return match ($kind) {
            'message_received' => self::Message,
            'friend_requested' => self::Friend,
            default => self::Other,
        };
    }

    /** The badge the skin positions over this compartment's icon. */
    public function badgeId(): string
    {
        return match ($this) {
            self::Message => 'nc_icon1',
            self::Friend => 'nc_icon2',
            self::Other => 'nc_icon3',
        };
    }
}
