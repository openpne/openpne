<?php

declare(strict_types=1);

namespace App\Features\Home\Serializers;

use App\Features\Diary\Serializers\DiarySerializer;
use App\Features\GroupEvent\Serializers\GroupEventSerializer;
use App\Features\GroupTopic\Serializers\GroupTopicSerializer;
use App\Features\Home\Data\HomeIssueDay;
use App\Features\Home\Data\HomeIssueWindow;
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
 * **Every story travels whole.** The page is for reading, not for indexing: each story carries its
 * body, its pictures and its counts in rank order, and how much of a body is shown is the page's
 * business rather than the payload's. Every optional key is absent when its section is empty —
 * never `[]` — so nothing on the page has to decide what an empty list means on screen.
 *
 * What is there is what SURVIVED the gate, not what was published: an issue of eight stories seven
 * of which have since been taken down is an issue of one.
 */
final class HomeIssueSerializer
{
    /**
     * @return array{issue: array|null, prev: array|null, next: array|null}
     */
    public static function page(
        ?HomeIssue $issue,
        ?HydratedIssue $hydrated,
        Member $viewer,
        ?HomeIssue $previous,
        ?HomeIssue $next,
        CarbonImmutable $now,
    ): array {
        return [
            'issue' => $issue === null || $hydrated === null ? null : self::issue($issue, $hydrated, $viewer, $now),
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

    private static function issue(HomeIssue $issue, HydratedIssue $hydrated, Member $viewer, CarbonImmutable $now): array
    {
        $window = new HomeIssueWindow(
            CarbonImmutable::parse($issue->window_start),
            CarbonImmutable::parse($issue->published_at),
        );

        return [
            ...self::linkTo($issue),
            'publishedAt' => CarbonImmutable::parse($issue->published_at)->toIso8601String(),
            // Which days the issue is ABOUT, which is not the same as its stretch: a day of
            // happenings runs 06:00 to 06:00 (HomeIssueDay), so the masthead names days and the
            // colophon names the instants they were drawn from.
            'days' => [
                'from' => $window->firstDay()->format('Y-m-d'),
                'to' => $window->lastDay()->format('Y-m-d'),
            ],
            'window' => [
                'from' => $window->start->toIso8601String(),
                'to' => $window->end->toIso8601String(),
            ],
            // Whether the page is showing what there is. Not "is it dated today": the issue a reader
            // is handed all day covers the day before, and comparing it to the calendar would make
            // every fresh front page announce itself as stale.
            'isCurrent' => CarbonImmutable::parse($issue->issue_date)->startOfDay()
                ->greaterThanOrEqualTo(HomeIssueDay::latest($now)),
            ...self::section('stories', $hydrated->items(HomeIssueSection::Stories),
                fn (array $items): array => array_map(fn (HydratedItem $item): array => self::story($item, $viewer), $items)),
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

    /**
     * A run of talk: how much was said, the end of it to read, and the group it was said in.
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
            'messages' => $burst['messages'],
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
