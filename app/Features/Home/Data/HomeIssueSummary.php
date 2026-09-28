<?php

declare(strict_types=1);

namespace App\Features\Home\Data;

use App\Models\Diary;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupTopic;
use App\Models\Member;
use App\Models\TimelinePost;
use Illuminate\Database\Eloquent\Model;

/**
 * What survives of one issue for one reader, as much of it as a month's row draws; each list keeps
 * the rank it was published in (docs/internals/home-issues.md, "The month page").
 */
final readonly class HomeIssueSummary
{
    /**
     * @param  list<Diary|TimelinePost|GroupTopic|GroupEvent>  $stories
     * @param  list<array{group: Group, count: int}>  $bursts
     * @param  list<Member>  $newcomers
     * @param  list<Group>  $newGroups
     */
    public function __construct(
        public array $stories = [],
        public array $bursts = [],
        public array $newcomers = [],
        public array $newGroups = [],
    ) {}

    /** @return array{stories: int, responses: int, talk: int, newcomers: int, newGroups: int} */
    public function counts(): array
    {
        return [
            'stories' => count($this->stories),
            'responses' => array_sum(array_map(
                fn (Model $story): int => (int) ($story instanceof TimelinePost ? $story->replies_count : $story->comments_count),
                $this->stories,
            )),
            'talk' => array_sum(array_column($this->bursts, 'count')),
            'newcomers' => count($this->newcomers),
            'newGroups' => count($this->newGroups),
        ];
    }

    /** The source the row leads with, or null when nothing survived. */
    public function top(): ?Model
    {
        return $this->stories[0]
            ?? $this->bursts[0]['group']
            ?? $this->newcomers[0]
            ?? $this->newGroups[0]
            ?? null;
    }
}
