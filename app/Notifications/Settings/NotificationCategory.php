<?php

declare(strict_types=1);

namespace App\Notifications\Settings;

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
     * Raw headings (pre-__()) for categories that actually render: those with at least one wired kind.
     * A category whose kinds are all unwired never surfaces, so requiring its ja translation would be
     * speculative. Distinct from sourceStrings() (all categories, for the term-literal gate).
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
