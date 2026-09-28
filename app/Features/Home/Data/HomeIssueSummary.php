<?php

declare(strict_types=1);

namespace App\Features\Home\Data;

use App\Models\Diary;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupTopic;
use App\Models\Member;
use App\Models\TimelinePost;

/**
 * What survives of one issue for one reader, as much of it as a month draws; each list keeps the
 * rank it was published in (docs/internals/home-issues.md, "The month page").
 */
final readonly class HomeIssueSummary
{
    public const SHOWN = 3;

    /**
     * @param  list<Diary|TimelinePost|GroupTopic|GroupEvent>  $stories
     * @param  list<TalkStretch>  $bursts
     * @param  list<Member>  $newcomers
     * @param  list<Group>  $newGroups
     */
    public function __construct(
        public array $stories = [],
        public array $bursts = [],
        public array $newcomers = [],
        public array $newGroups = [],
    ) {}

    /**
     * The lead story, the busiest room, the second story; a kind that has run out gives its place to
     * the other, so a day with talk in it always shows some.
     *
     * @return list<Diary|TimelinePost|GroupTopic|GroupEvent|TalkStretch>
     */
    public function items(): array
    {
        $stories = $this->stories;
        $bursts = $this->bursts;
        $items = [];

        foreach ([true, false, true] as $storyFirst) {
            $next = $storyFirst
                ? array_shift($stories) ?? array_shift($bursts)
                : array_shift($bursts) ?? array_shift($stories);

            if ($next === null) {
                break;
            }

            $items[] = $next;
        }

        return $items;
    }

    public function more(): int
    {
        return count($this->stories) + count($this->bursts) - count($this->items());
    }

    /** @param  list<TalkStretch>  $bursts */
    public function withBursts(array $bursts): self
    {
        return new self($this->stories, $bursts, $this->newcomers, $this->newGroups);
    }
}
