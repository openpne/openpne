<?php

declare(strict_types=1);

namespace App\Features\Home;

use App\Support\Feature;
use InvalidArgumentException;

/**
 * The case value is what the ledger stores, so renaming one is a data migration.
 */
enum HomeIssueSection: string
{
    case Stories = 'stories';

    case Talk = 'talk';

    case Newcomers = 'newcomers';

    case NewGroups = 'new_groups';

    case UpcomingEvents = 'upcoming_events';

    /**
     * A cap, not a target: a quiet day publishes fewer and the page never pads
     * (docs/internals/home-issues.md, "Ranking, caps and the tiebreak").
     */
    public function cap(): int
    {
        return match ($this) {
            self::Stories => 8,
            self::Talk => 3,
            self::Newcomers => 12,
            self::NewGroups => 6,
            self::UpcomingEvents => 6,
        };
    }

    /**
     * True where the item is about a window or a calendar rather than about the thing itself: a busy
     * group is news again next week, and an event is worth listing until it happens. Everywhere else
     * the item IS the thing, and showing it twice would say something new happened when nothing did.
     */
    public function recurs(): bool
    {
        return match ($this) {
            self::Talk, self::UpcomingEvents => true,
            default => false,
        };
    }

    /**
     * The complement of recurs(): the sections the ledger is a never-again memory for.
     *
     * @return list<self>
     */
    public static function neverAgain(): array
    {
        return array_values(array_filter(self::cases(), fn (self $section): bool => ! $section->recurs()));
    }

    /**
     * The feature unit gating this (section, source) pair, or null where no unit owns it.
     *
     * The pair matters, not the source alone: a group is gated by group talk when it is here for what
     * was said in it, and by groups themselves when it is here for being new.
     *
     * @throws InvalidArgumentException when the section may not hold this alias at all
     */
    public function unit(string $sourceType): ?Feature
    {
        $sources = $this->sources();

        // A stored row whose alias its section does not know is a bug in whatever wrote it, not data
        // to render around, so this is louder than a null.
        if (! array_key_exists($sourceType, $sources)) {
            throw new InvalidArgumentException("Section [{$this->value}] does not hold [{$sourceType}] sources.");
        }

        return $sources[$sourceType];
    }

    public function allowsSource(string $sourceType): bool
    {
        return array_key_exists($sourceType, $this->sources());
    }

    /**
     * Public so the guards can enumerate the aliases: one added below cannot then go unexamined.
     *
     * @return list<string>
     */
    public function sourceTypes(): array
    {
        return array_keys($this->sources());
    }

    /**
     * The one map both questions are answered from: which aliases a section holds, and what gates
     * each. Keys are morph aliases (App\Providers\AppServiceProvider), never class names — the ledger
     * stores the alias.
     *
     * @return array<string, Feature|null>
     */
    private function sources(): array
    {
        return match ($this) {
            self::Stories => [
                'timelinePost' => Feature::Timeline,
                'diary' => Feature::Diary,
                'groupTopic' => Feature::GroupTopic,
                'groupEvent' => Feature::GroupEvent,
            ],
            self::Talk => ['group' => Feature::GroupTalk],
            // Members are not a togglable unit: there is no SNS with them switched off.
            self::Newcomers => ['member' => null],
            self::NewGroups => ['group' => Feature::Group],
            self::UpcomingEvents => ['groupEvent' => Feature::GroupEvent],
        };
    }
}
