<?php

declare(strict_types=1);

namespace App\Notifications\Settings;

use App\Support\Feature;

/**
 * The notification-catalog categories, used to group kinds on the settings pages.
 */
enum NotificationCategory: string
{
    case Timeline = 'timeline';
    case Diary = 'diary';
    case CommunityTopic = 'community_topic';
    case CommunityEvent = 'community_event';
    case FriendLink = 'friend_link';
    case Message = 'message';

    /** The feature unit whose notifications this category configures; off means it has nothing to offer. */
    public function feature(): Feature
    {
        return match ($this) {
            self::Timeline => Feature::Timeline,
            self::Diary => Feature::Diary,
            self::CommunityTopic => Feature::CommunityTopic,
            self::CommunityEvent => Feature::CommunityEvent,
            self::FriendLink => Feature::Friend,
            self::Message => Feature::Message,
        };
    }

    /** Member-facing group heading (translated; %term% placeholders resolve downstream). */
    public function caption(): string
    {
        return __($this->sourceCaption());
    }

    private function sourceCaption(): string
    {
        return match ($this) {
            self::Timeline => '%Activity%',
            self::Diary => '%Diaries%',
            self::CommunityTopic => '%Community% %topics%',
            self::CommunityEvent => '%Community% events',
            self::FriendLink => '%Friend% requests',
            self::Message => 'Messages',
        };
    }

    /**
     * Raw caption source strings (pre-__()). Exposed so the i18n:check term-literal gate can
     * scan captions that reach __() via a variable and never enter the code scanner.
     *
     * @return list<string>
     */
    public static function sourceStrings(): array
    {
        return array_map(static fn (self $category): string => $category->sourceCaption(), self::cases());
    }

    /**
     * Raw headings (pre-__()) of categories that render — those with at least one wired kind. An
     * all-unwired category never surfaces, so gating its ja translation would be speculative.
     *
     * @return list<string>
     */
    public static function coverageStrings(): array
    {
        $seen = [];
        $out = [];
        foreach (NotificationKind::wiredCases() as $kind) {
            $category = $kind->category();
            if (! isset($seen[$category->value])) {
                $seen[$category->value] = true;
                $out[] = $category->sourceCaption();
            }
        }

        return $out;
    }
}
