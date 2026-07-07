<?php

declare(strict_types=1);

namespace App\Notifications\Settings;

/**
 * The OpenPNE 3 notification_config.yml categories, used to group kinds on the settings pages.
 */
enum NotificationCategory: string
{
    case Timeline = 'timeline';
    case Diary = 'diary';
    case CommunityTopic = 'community_topic';
    case CommunityEvent = 'community_event';
    case FriendLink = 'friend_link';
    case Message = 'message';

    /** Member-facing group heading (untranslated source string; %term% placeholders apply). */
    public function caption(): string
    {
        return match ($this) {
            self::Timeline => 'Timeline',
            self::Diary => 'Diary',
            self::CommunityTopic => '%Community% topics',
            self::CommunityEvent => '%Community% events',
            self::FriendLink => '%Friend% requests',
            self::Message => 'Messages',
        };
    }
}
