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
use App\Features\Member\Serializers\MemberRefSerializer;
use App\Features\Timeline\Serializers\TimelinePostSerializer;
use App\Models\Diary;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupTopic;
use App\Models\HomeIssue;
use App\Models\TimelinePost;
use App\Support\BodyFormat;
use App\Support\BodyRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The issue page's payload.
 *
 * **A story travels as a headline, a dek and a picture** — never a body. The front page is where a
 * reader chooses what to read, and the place to read it is its own page, so nothing here carries
 * words the block does not print: no rendered HTML, no link card, no entity ranges. Every optional
 * key is absent when its section is empty — never `[]` — so nothing on the page has to decide what
 * an empty list means on screen.
 *
 * What is there is what SURVIVED the gate, not what was published: an issue of eight stories seven
 * of which have since been taken down is an issue of one.
 */
final class HomeIssueSerializer
{
    /**
     * How much of a body a dek carries, as a display width (a fullwidth glyph spends two of it).
     * Wider than the feed's row-height cut: a dek is two or three lines of a card, not one line of
     * a list, and it is the only thing on the page saying what a story is about.
     */
    private const DEK_WIDTH = 180;

    /**
     * @return array{issue: array|null, prev: array|null, next: array|null}
     */
    public static function page(
        ?HomeIssue $issue,
        ?HydratedIssue $hydrated,
        ?HomeIssue $previous,
        ?HomeIssue $next,
        CarbonImmutable $now,
    ): array {
        return [
            'issue' => $issue === null || $hydrated === null ? null : self::issue($issue, $hydrated, $now),
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

    private static function issue(HomeIssue $issue, HydratedIssue $hydrated, CarbonImmutable $now): array
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
                fn (array $items): array => array_map(self::story(...), $items)),
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
     * One story, as much of it as a front page prints: what it is called, the line it opens with,
     * and one picture. A board entry adds the group it was posted in, which its byline names.
     */
    private static function story(HydratedItem $hydrated): array
    {
        $source = $hydrated->source;

        return match (true) {
            $source instanceof Diary => self::card(
                'diary', $source, "/diary/{$source->getKey()}", $source->title,
                self::dek($source->body, $source->format),
                null,
                self::countOf($source, 'comments'),
                self::picture($source->images, DiarySerializer::image(...)),
            ),
            $source instanceof TimelinePost => self::post($source),
            $source instanceof GroupTopic => self::card(
                'topic', $source, "/topics/{$source->getKey()}", $source->name,
                self::dek($source->body, $source->format),
                self::scope($source->group),
                self::countOf($source, 'comments'),
                self::picture($source->images, GroupTopicSerializer::image(...)),
            ),
            $source instanceof GroupEvent => self::card(
                'event', $source, "/events/{$source->getKey()}", $source->name,
                self::dek($source->body, $source->format),
                self::scope($source->group),
                self::countOf($source, 'comments'),
                self::picture($source->images, GroupEventSerializer::image(...)),
            ),
        };
    }

    /**
     * A post has no title, so the line its author opened with stands in for one and the dek is what
     * is left after it. A post opening on a blank line has no such line — and the block is one link
     * named by its headline, which cannot then be nothing — so there the words themselves headline
     * it and the dek stands down.
     */
    private static function post(TimelinePost $post): array
    {
        $lead = trim(self::firstLine($post->body));
        $rest = self::dek(self::afterFirstLine($post->body), BodyFormat::Plain);

        return self::card(
            'timeline', $post, "/timeline/{$post->getKey()}",
            $lead === '' ? $rest : $lead,
            $lead === '' ? '' : $rest,
            null,
            self::countOf($post, 'replies'),
            self::picture($post->images, TimelinePostSerializer::image(...)),
        );
    }

    /**
     * The fields every story has, whatever it is. `kind` is what the byline is written in, not a
     * shape switch: one shape, and what a kind does not have is null rather than a key the page has
     * to ask about — a diary has no group the way a story with no photograph has no picture.
     */
    private static function card(
        string $kind,
        Diary|TimelinePost|GroupTopic|GroupEvent $source,
        string $href,
        string $headline,
        string $dek,
        ?array $group,
        int $commentCount,
        ?array $image,
    ): array {
        return [
            'kind' => $kind,
            'id' => (int) $source->getKey(),
            'href' => $href,
            'headline' => $headline,
            'dek' => $dek,
            'author' => $source->member === null ? null : MemberRefSerializer::ref($source->member),
            'group' => $group,
            'createdAt' => $source->created_at->toIso8601String(),
            'commentCount' => $commentCount,
            'image' => $image,
        ];
    }

    /** What a story says, in plain text, cut at the width a dek is read at. */
    private static function dek(?string $body, BodyFormat $format): string
    {
        return BodyRenderer::excerpt($body, $format, self::DEK_WIDTH);
    }

    /**
     * The one picture a block draws: the first posted with the story, in the shape every grid
     * picture travels in. A row whose file is gone is skipped rather than drawn as an empty box.
     *
     * @param  Collection<int, Model>  $images
     * @param  callable(Model): array  $shape
     */
    private static function picture(Collection $images, callable $shape): ?array
    {
        $first = $images->first(fn (Model $image): bool => $image->file !== null);

        return $first === null ? null : $shape($first);
    }

    /** A count the eager load already made, or one asked for now rather than reported as zero. */
    private static function countOf(Model $source, string $relation): int
    {
        $key = "{$relation}_count";

        return (int) ($source->{$key} ?? $source->loadCount($relation)->{$key});
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

    /** A body's first line, counted in code points so no astral character is cut in half. */
    private static function firstLine(?string $body): string
    {
        $break = mb_strpos((string) $body, "\n");

        return $break === false ? (string) $body : mb_substr((string) $body, 0, $break);
    }

    /** The same body with that line taken off; empty when there was no break to take it at. */
    private static function afterFirstLine(?string $body): string
    {
        $break = mb_strpos((string) $body, "\n");

        return $break === false ? '' : mb_substr((string) $body, $break + 1);
    }
}
