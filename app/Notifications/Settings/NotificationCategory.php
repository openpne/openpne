<?php

declare(strict_types=1);

namespace App\Notifications\Settings;

use App\Support\Feature;

enum NotificationCategory: string
{
    case Timeline = 'timeline';
    case Diary = 'diary';
    case GroupTopic = 'group_topic';
    case GroupEvent = 'group_event';
    case GroupTalk = 'group_talk';
    case FriendLink = 'friend_link';
    case DirectMessage = 'direct_message';

    public function feature(): Feature
    {
        return match ($this) {
            self::Timeline => Feature::Timeline,
            self::Diary => Feature::Diary,
            self::GroupTopic => Feature::GroupTopic,
            self::GroupEvent => Feature::GroupEvent,
            self::GroupTalk => Feature::GroupTalk,
            self::FriendLink => Feature::Friend,
            self::DirectMessage => Feature::DirectMessage,
        };
    }

    /** Translated, but its %term% placeholders are resolved downstream. */
    public function caption(): string
    {
        return __($this->sourceCaption());
    }

    private function sourceCaption(): string
    {
        return match ($this) {
            self::Timeline => '%Activity%',
            self::Diary => '%Diaries%',
            self::GroupTopic => '%Community% %topics%',
            self::GroupEvent => '%Community% events',
            self::GroupTalk => '%Community% talk',
            self::FriendLink => '%Friend% requests',
            self::DirectMessage => 'Messages',
        };
    }

    /**
     * Read by the i18n:check term-literal gate: a string reaching __() through a variable never enters
     * the code scanner.
     *
     * @return list<string>
     */
    public static function sourceStrings(): array
    {
        return array_map(static fn (self $category): string => $category->sourceCaption(), self::cases());
    }

    /**
     * Only a category with a wired kind renders, so gating an all-unwired one's ja translation would be
     * speculative.
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
