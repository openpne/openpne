<?php

declare(strict_types=1);

namespace App\Features\Home\Serializers;

use App\Features\Diary\Serializers\DiarySerializer;
use App\Features\GroupEvent\Serializers\GroupEventSerializer;
use App\Features\GroupTopic\Serializers\GroupTopicSerializer;
use App\Features\Home\Data\HydratedIssue;
use App\Features\Home\Data\HydratedItem;
use App\Features\Home\HomeIssueSection;
use App\Features\Timeline\Serializers\TimelinePostSerializer;
use App\Models\Diary;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupTopic;
use App\Models\HomeIssue;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\BodyRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The issue page's payload.
 *
 * **The layout is stated, not described.** How much of an issue there is decides how it is drawn,
 * and rather than ship a mode for the page to interpret, the shape says it: one story is a
 * `topStory` alone, two or three add `features`, four or more add `briefs`. Every optional key is
 * absent when its section is empty — never `[]` — so nothing on the page has to decide what an
 * empty list means on screen.
 *
 * The count is taken from what SURVIVED the gate, not from what was published: an issue of eight
 * stories seven of which have since been taken down is an issue of one, and drawing it as a lead
 * over a list of nothing would report the seven.
 */
final class HomeIssueSerializer
{
    /** Stories past the lead that still stand abreast of it, rather than becoming a list. */
    private const FEATURES = 2;

    /**
     * @return array{issue: array|null, prev: array|null, next: array|null}
     */
    public static function page(
        ?HomeIssue $issue,
        ?HydratedIssue $hydrated,
        Member $viewer,
        ?HomeIssue $previous,
        ?HomeIssue $next,
        CarbonImmutable $today,
    ): array {
        return [
            'issue' => $issue === null || $hydrated === null ? null : self::issue($issue, $hydrated, $viewer, $today),
            'prev' => self::ref($previous),
            'next' => self::ref($next),
        ];
    }

    /**
     * An issue as something to link to. Null-transparent, so a caller can forward a missing
     * neighbour straight through.
     *
     * @return array{date: string, number: int, href: string}|null
     */
    public static function ref(?HomeIssue $issue): ?array
    {
        return $issue === null ? null : self::linkTo($issue);
    }

    /**
     * The archive index: the run of issues as links, with the pager state the list reads.
     *
     * @param  LengthAwarePaginator<int, HomeIssue>  $issues
     * @return array{issues: array{data: list<array>, meta: array{currentPage: int, lastPage: int, perPage: int, total: int}}}
     */
    public static function archive(LengthAwarePaginator $issues): array
    {
        return [
            'issues' => [
                'data' => array_map(self::linkTo(...), $issues->items()),
                'meta' => [
                    'currentPage' => $issues->currentPage(),
                    'lastPage' => $issues->lastPage(),
                    'perPage' => $issues->perPage(),
                    'total' => $issues->total(),
                ],
            ],
        ];
    }

    /** @return array{date: string, number: int, href: string} */
    private static function linkTo(HomeIssue $issue): array
    {
        $date = CarbonImmutable::parse($issue->issue_date);

        return [
            // A civil date, never an instant: the day an issue covers has no time in it, and an ISO
            // midnight would land a day west of UTC in the reader's browser.
            'date' => $date->format('Y-m-d'),
            'number' => (int) $issue->number,
            'href' => '/home/'.$date->format('Y/m/d'),
        ];
    }

    private static function issue(HomeIssue $issue, HydratedIssue $hydrated, Member $viewer, CarbonImmutable $today): array
    {
        return [
            ...self::linkTo($issue),
            'publishedAt' => CarbonImmutable::parse($issue->published_at)->toIso8601String(),
            // The site's own day, not the reader's: an issue is one page for everybody, and its
            // colophon says whether it is today's.
            'isCurrent' => CarbonImmutable::parse($issue->issue_date)->format('Y-m-d') === $today->format('Y-m-d'),
            ...self::stories($hydrated->items(HomeIssueSection::Stories), $viewer),
            ...self::section('talkBursts', $hydrated->items(HomeIssueSection::Talk),
                fn (array $items): array => array_map(self::burst(...), $items)),
            ...self::section('newcomers', $hydrated->items(HomeIssueSection::Newcomers),
                fn (array $items): array => UnifiedSections::people(self::sourcesOf($items))),
            ...self::section('newGroups', $hydrated->items(HomeIssueSection::NewGroups),
                fn (array $items): array => UnifiedSections::groups(self::sourcesOf($items))),
            ...self::section('upcomingEvents', $hydrated->items(HomeIssueSection::UpcomingEvents),
                fn (array $items): array => array_map(self::upcomingEvent(...), $items)),
        ];
    }

    /**
     * The lead and what follows it, in whichever of the three shapes the surviving count picks.
     *
     * @param  list<HydratedItem>  $stories
     */
    private static function stories(array $stories, Member $viewer): array
    {
        $lead = array_shift($stories);

        // Every story this issue featured has since gone. The rest of it still stands, so the key is
        // absent rather than the issue being nothing.
        if ($lead === null) {
            return [];
        }

        $payload = ['topStory' => self::story($lead, $viewer)];

        if ($stories === []) {
            return $payload;
        }

        // Two or three stand abreast as equals and are drawn whole; past that the lead keeps its
        // card and the rest become rows, which read at a glance in the shape their own lists use.
        return count($stories) <= self::FEATURES
            ? [...$payload, 'features' => array_map(fn (HydratedItem $item): array => self::story($item, $viewer), $stories)]
            : [...$payload, 'briefs' => array_map(fn (HydratedItem $item): array => self::brief($item, $viewer), $stories)];
    }

    /**
     * One story carried whole: the shape its own show page ships, plus what a headline needs that
     * the record does not carry.
     */
    private static function story(HydratedItem $hydrated, Member $viewer): array
    {
        $source = $hydrated->source;

        return match (true) {
            $source instanceof Diary => ['kind' => 'diary', 'item' => DiarySerializer::detail($source, $viewer)],
            $source instanceof TimelinePost => ['kind' => 'timeline', 'item' => [
                ...TimelinePostSerializer::entry($source, $viewer),
                // A post has no title, so the page headlines it with the line its author opened
                // with. The excerpt is therefore what is left AFTER that line — repeating it would
                // print the headline twice — and a one-line post has nothing left to show.
                'excerpt' => self::afterFirstLine($source->body),
            ]],
            $source instanceof GroupTopic => ['kind' => 'topic', 'item' => [
                ...GroupTopicSerializer::detail($source, $viewer),
                'group' => self::scope($source->group),
                'excerpt' => BodyRenderer::excerpt($source->body, $source->format),
                'commentCount' => (int) ($source->comments_count ?? $source->loadCount('comments')->comments_count),
            ]],
            $source instanceof GroupEvent => ['kind' => 'event', 'item' => [
                ...GroupEventSerializer::detail($source, $viewer),
                'group' => self::scope($source->group),
                'excerpt' => BodyRenderer::excerpt($source->body, $source->format),
                'commentCount' => (int) ($source->comments_count ?? $source->loadCount('comments')->comments_count),
            ]],
        };
    }

    /** One story below the lead, in the row shape the rest of the surface already lists it with. */
    private static function brief(HydratedItem $hydrated, Member $viewer): array
    {
        $source = $hydrated->source;

        return match (true) {
            $source instanceof Diary => ['kind' => 'diary', 'item' => DiarySerializer::summary($source)],
            $source instanceof TimelinePost => ['kind' => 'timeline', 'item' => TimelinePostSerializer::entry($source, $viewer)],
            $source instanceof GroupTopic => ['kind' => 'topic', 'item' => HomeSerializer::activityEntry($source)],
            $source instanceof GroupEvent => ['kind' => 'event', 'item' => HomeSerializer::activityEntry($source)],
        };
    }

    /**
     * A run of talk: the live numbers the gate resolved, under the group they were said in.
     *
     * Nothing here comes from the row's frozen stats — those record why it was chosen, and are never
     * re-read as current truth ([home-issues.md](../../../../docs/internals/home-issues.md)).
     */
    private static function burst(HydratedItem $hydrated): array
    {
        /** @var Group $group */
        $group = $hydrated->source;
        $burst = $hydrated->extra;

        return [
            'group' => self::scope($group),
            'count' => $burst['count'],
            'since' => $burst['since']->toIso8601String(),
            'participants' => $burst['participants'],
            'thumbnails' => $burst['thumbnails'],
            'href' => $burst['href'],
        ];
    }

    /** A calendar row: the activity row's fields, plus the day the gathering falls on. */
    private static function upcomingEvent(HydratedItem $hydrated): array
    {
        /** @var GroupEvent $event */
        $event = $hydrated->source;

        return [
            ...HomeSerializer::activityEntry($event),
            // Y-m-d, no instant: an open date is a civil date and the row draws it as one.
            'openDate' => $event->open_date->format('Y-m-d'),
        ];
    }

    /**
     * A section key, present only when the section has something in it.
     *
     * @param  list<HydratedItem>  $items
     * @param  callable(list<HydratedItem>): list<array>  $shape
     */
    private static function section(string $key, array $items, callable $shape): array
    {
        return $items === [] ? [] : [$key => $shape($items)];
    }

    /**
     * @param  list<HydratedItem>  $items
     * @return Collection<int, Model>
     */
    private static function sourcesOf(array $items): Collection
    {
        return collect($items)->map(fn (HydratedItem $item): Model => $item->source);
    }

    /**
     * The group a board entry or a burst belongs to, as much of it as a byline draws — the avatar
     * size the activity row's byline uses, not the larger tile a group grid serves.
     *
     * @return array{id: int, name: string, imageUrl: string|null}
     */
    private static function scope(Group $group): array
    {
        return [
            'id' => (int) $group->getKey(),
            'name' => $group->name,
            'imageUrl' => $group->image?->thumbnailUrl(120, 120, square: true),
        ];
    }

    /**
     * A body with its first line taken off, counted in code points — the unit the client splits the
     * same body in (resources/js/pages/home/lead.ts), so the two cuts fall in the same place.
     */
    private static function afterFirstLine(?string $body): string
    {
        $break = mb_strpos((string) $body, "\n");

        return $break === false ? '' : mb_substr((string) $body, $break + 1);
    }
}
