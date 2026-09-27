<?php

declare(strict_types=1);

namespace App\Features\Home\Serializers;

use App\Features\Diary\Serializers\DiarySerializer;
use App\Features\GroupEvent\Serializers\GroupEventSerializer;
use App\Features\GroupTopic\Serializers\GroupTopicSerializer;
use App\Features\Home\Data\HomeIssueDay;
use App\Features\Home\Data\HomeIssueMonth;
use App\Features\Home\Data\HomeIssueSummary;
use App\Features\Home\Data\HomeIssueWindow;
use App\Features\Home\Data\HydratedIssue;
use App\Features\Home\Data\HydratedItem;
use App\Features\Home\HeatScale;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The issue page's payload: a story travels as a headline, a dek and a picture — never a body, no
 * rendered HTML, no link card, no entity ranges — and every optional key is absent when its section
 * is empty rather than `[]` (docs/internals/home-issues.md, "Rendering"). What is there is what
 * survived the gate, not what was published.
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
     * Null-transparent, so a caller can forward a missing neighbour straight through.
     *
     * @return array{date: string, number: int, href: string}|null
     */
    public static function ref(?HomeIssue $issue): ?array
    {
        return $issue === null ? null : self::linkTo($issue);
    }

    /**
     * A null month is a site that has published nothing, which has no month to be in.
     *
     * @param  Collection<int, HomeIssue>  $issues  newest first
     * @param  array<int, HomeIssueSummary>  $summaries  keyed by issue id
     * @param  list<array{year: int, month: int, count: int}>  $months  every month that holds an issue
     * @return array{month: array|null, prev: array|null, next: array|null, days: list<array>, months: list<array>}
     */
    public static function month(
        ?HomeIssueMonth $month,
        Collection $issues,
        array $summaries,
        ?HomeIssueMonth $previous,
        ?HomeIssueMonth $next,
        array $months = [],
    ): array {
        $levels = HeatScale::levels(array_map(
            fn (HomeIssueSummary $summary): int => $summary->activity(),
            $summaries,
        ));

        return [
            'month' => self::monthRef($month),
            'prev' => self::monthRef($previous),
            'next' => self::monthRef($next),
            'days' => $issues
                ->map(fn (HomeIssue $issue): array => self::day(
                    $issue,
                    $summaries[(int) $issue->getKey()] ?? new HomeIssueSummary,
                    $levels[(int) $issue->getKey()] ?? 0,
                ))
                ->values()
                ->all(),
            'months' => $months,
        ];
    }

    /** @return array{year: int, month: int, href: string}|null */
    private static function monthRef(?HomeIssueMonth $month): ?array
    {
        return $month === null ? null : ['year' => $month->year, 'month' => $month->month, 'href' => $month->href()];
    }

    /** `top` is null when nothing survived, and the row is then its date alone. */
    private static function day(HomeIssue $issue, HomeIssueSummary $summary, int $level): array
    {
        return [
            ...self::linkTo($issue),
            'days' => self::daysOf(self::windowOf($issue)),
            'counts' => $summary->counts(),
            'level' => $level,
            'top' => self::top($summary),
        ];
    }

    private static function top(HomeIssueSummary $summary): ?array
    {
        $top = $summary->top();

        return match (true) {
            $top === null => null,
            $summary->stories !== [] => [
                'kind' => 'story',
                'headline' => self::headline($top),
                'image' => self::pictureOf($top),
            ],
            $summary->bursts !== [] => [
                'kind' => 'talk',
                'group' => self::scope($top),
            ],
            $summary->newcomers !== [] => [
                'kind' => 'newcomer',
                'member' => MemberRefSerializer::ref($top),
                'others' => count($summary->newcomers) - 1,
            ],
            default => [
                'kind' => 'newGroup',
                'group' => self::scope($top),
            ],
        };
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

    private static function windowOf(HomeIssue $issue): HomeIssueWindow
    {
        return new HomeIssueWindow(
            CarbonImmutable::parse($issue->window_start),
            CarbonImmutable::parse($issue->published_at),
        );
    }

    /**
     * Which days an issue is ABOUT, which is not the same as its stretch: a day of happenings runs
     * 06:00 to 06:00 (HomeIssueDay).
     *
     * @return array{from: string, to: string}
     */
    private static function daysOf(HomeIssueWindow $window): array
    {
        return [
            'from' => $window->firstDay()->format('Y-m-d'),
            'to' => $window->lastDay()->format('Y-m-d'),
        ];
    }

    private static function issue(HomeIssue $issue, HydratedIssue $hydrated, CarbonImmutable $now): array
    {
        $window = self::windowOf($issue);

        return [
            ...self::linkTo($issue),
            'monthHref' => HomeIssueMonth::of(CarbonImmutable::parse($issue->issue_date))->href(),
            // The masthead names the days and the colophon the instants they were drawn from.
            'days' => self::daysOf($window),
            'window' => [
                'from' => $window->start->toIso8601String(),
                'to' => $window->end->toIso8601String(),
            ],
            // Whether the page is showing what there is, not whether it is dated today: a fresh
            // front page covers the day before, and the calendar would call it stale.
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

    private static function story(HydratedItem $hydrated): array
    {
        $source = $hydrated->source;

        return match (true) {
            $source instanceof Diary => self::card(
                'diary', $source, "/diary/{$source->getKey()}",
                self::dek($source->body, $source->format),
                null,
                self::countOf($source, 'comments'),
            ),
            $source instanceof TimelinePost => self::card(
                'timeline', $source, "/timeline/{$source->getKey()}",
                self::postLines($source)[1],
                null,
                self::countOf($source, 'replies'),
            ),
            $source instanceof GroupTopic => self::card(
                'topic', $source, "/topics/{$source->getKey()}",
                self::dek($source->body, $source->format),
                self::scope($source->group),
                self::countOf($source, 'comments'),
            ),
            $source instanceof GroupEvent => self::card(
                'event', $source, "/events/{$source->getKey()}",
                self::dek($source->body, $source->format),
                self::scope($source->group),
                self::countOf($source, 'comments'),
            ),
        };
    }

    private static function headline(Diary|TimelinePost|GroupTopic|GroupEvent $source): string
    {
        return match (true) {
            $source instanceof Diary => (string) $source->title,
            $source instanceof TimelinePost => self::postLines($source)[0],
            default => (string) $source->name,
        };
    }

    /**
     * A post has no title, so its opening line stands in for one and the dek is what is left after
     * it. A post opening on a blank line is headlined by its words instead, since the block is one
     * link that cannot be named by nothing.
     *
     * @return array{string, string} the headline, then the dek
     */
    private static function postLines(TimelinePost $post): array
    {
        $lead = trim(self::firstLine($post->body));
        $rest = self::dek(self::afterFirstLine($post->body), BodyFormat::Plain);

        return $lead === '' ? [$rest, ''] : [$lead, $rest];
    }

    private static function pictureOf(Diary|TimelinePost|GroupTopic|GroupEvent $source): ?array
    {
        return self::picture($source->images, match (true) {
            $source instanceof Diary => DiarySerializer::image(...),
            $source instanceof TimelinePost => TimelinePostSerializer::image(...),
            $source instanceof GroupTopic => GroupTopicSerializer::image(...),
            $source instanceof GroupEvent => GroupEventSerializer::image(...),
        });
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
        string $dek,
        ?array $group,
        int $commentCount,
    ): array {
        return [
            'kind' => $kind,
            'id' => (int) $source->getKey(),
            'href' => $href,
            'headline' => self::headline($source),
            'dek' => $dek,
            'author' => $source->member === null ? null : MemberRefSerializer::ref($source->member),
            'group' => $group,
            'createdAt' => $source->created_at->toIso8601String(),
            'commentCount' => $commentCount,
            'image' => self::pictureOf($source),
        ];
    }

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

    /** A count the eager load already made, or one this call asks for rather than reporting zero. */
    private static function countOf(Model $source, string $relation): int
    {
        $key = "{$relation}_count";

        return (int) ($source->{$key} ?? $source->loadCount($relation)->{$key});
    }

    /**
     * Nothing here comes from the row's frozen stats — those record why it was chosen, and are never
     * re-read as current truth (docs/internals/home-issues.md, "Frozen stats are provenance").
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
