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
    case GroupTopic = 'group_topic';
    case GroupEvent = 'group_event';
    case FriendLink = 'friend_link';
    case DirectMessage = 'direct_message';

    /** The feature unit whose notifications this category configures; off means it has nothing to offer. */
    public function feature(): Feature
    {
        return match ($this) {
            self::Timeline => Feature::Timeline,
            self::Diary => Feature::Diary,
            self::GroupTopic => Feature::GroupTopic,
            self::GroupEvent => Feature::GroupEvent,
            self::FriendLink => Feature::Friend,
            self::DirectMessage => Feature::DirectMessage,
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
            self::GroupTopic => '%Community% %topics%',
            self::GroupEvent => '%Community% events',
            self::FriendLink => '%Friend% requests',
            self::DirectMessage => 'Messages',
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
