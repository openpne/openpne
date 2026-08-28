<?php

declare(strict_types=1);

namespace App\Features\Home\Actions;

use App\Features\Home\Data\HomeIssuePlan;
use App\Features\Home\Data\HomeIssueWindow;
use App\Features\Home\Data\PlannedItem;
use App\Features\Home\Data\SourceRef;
use App\Features\Home\HomeIssueSection;
use App\Features\Home\Queries\DiaryStoryCandidates;
use App\Features\Home\Queries\EventStoryCandidates;
use App\Features\Home\Queries\LatestHomeIssue;
use App\Features\Home\Queries\NewcomerCandidates;
use App\Features\Home\Queries\NewGroupCandidates;
use App\Features\Home\Queries\StoryCandidates;
use App\Features\Home\Queries\TalkBurstCandidates;
use App\Features\Home\Queries\TimelineStoryCandidates;
use App\Features\Home\Queries\TopicStoryCandidates;
use App\Features\Home\Queries\UpcomingEventCandidates;
use App\Models\HomeIssue;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Builds one day's issue: what happened since the last one, and the ledger rows that say so.
 *
 * Two halves on purpose. {@see plan()} decides and writes nothing, so a dry run and a publish reach
 * their answer down the same path; {@see __invoke()} commits that decision in one transaction.
 *
 * **An issue is dated by the last day its window covers** ({@see HomeIssueDay}), never by the day it
 * is built on: the 06:00 run reports the day that just ended, and dating it today would headline
 * yesterday evening's posts with tomorrow's date.
 */
final class PublishHomeIssue
{
    /** Site-clock time of day an issue goes out. Read by the schedule (routes/console.php). */
    public const TIME = '06:00';

    /** How far the very first issue reaches back: there is no previous `published_at` to start from. */
    public const FIRST_WINDOW_DAYS = 7;

    public function __construct(
        private readonly LatestHomeIssue $latestIssue,
        private readonly TimelineStoryCandidates $timelineStories,
        private readonly DiaryStoryCandidates $diaryStories,
        private readonly TopicStoryCandidates $topicStories,
        private readonly EventStoryCandidates $eventStories,
        private readonly TalkBurstCandidates $talkBursts,
        private readonly NewcomerCandidates $newcomers,
        private readonly NewGroupCandidates $newGroups,
        private readonly UpcomingEventCandidates $upcomingEvents,
    ) {}

    /**
     * Publish the issue $now closes, or null on a 休刊.
     *
     * $pin forces one story to the top; it is held to the same eligibility as any candidate and
     * quietly dropped if it fails, which the plan reports rather than throwing. Nothing wires a pin
     * yet — the parameter is the seam the admin setting will arrive on.
     *
     * $window fixes both bounds instead of chaining from the previous issue, which is what a
     * backfilled day is (`--date`): the stretch is the day's own, and the issue is dated by it like
     * any other. The scheduled path passes none.
     *
     * Meant to run as the top-level transaction: nested inside another, the framework answers a
     * concurrency error with a DeadlockException that the catch below does not see.
     */
    public function __invoke(CarbonImmutable $now, ?SourceRef $pin = null, ?HomeIssueWindow $window = null): ?HomeIssue
    {
        $window ??= $this->window($now);

        $existing = $this->publishedOn($window->lastDay());
        if ($existing !== null) {
            return $existing;
        }

        $plan = $this->plan($now, $pin, $window);
        if ($plan === null) {
            return null;
        }

        try {
            // Retried, because MySQL and SQLite lose the race differently: MySQL's loser reaches the
            // insert and violates the unique, while SQLite compiles lockForUpdate away and refuses
            // the write itself with SQLITE_BUSY. A retry re-reads the maximum and turns the second
            // shape into the first.
            return DB::transaction(fn (): HomeIssue => $this->write($plan), attempts: 3);
        } catch (QueryException $e) {
            // Another run took this date between the check above and the insert — as a unique
            // violation, or as a busy database that stayed busy for every attempt. The unique on
            // issue_date is the guarantee either way: the loser has nothing to unwind and reports
            // what the winner wrote. A failure with no issue to report is a different fault (the
            // unique on `number`, a broken write), and stays loud.
            return $this->publishedOn($window->lastDay()) ?? throw $e;
        }
    }

    /**
     * The issue for $day if it has already been published — $day being the day an issue is dated by
     * ({@see HomeIssueWindow::lastDay()}), not the day a run happens on.
     *
     * `whereDate` rather than an equality on the day, because the two engines do not hold the column
     * alike: Eloquent writes a `date` cast through the connection's datetime format, which MySQL
     * truncates into its DATE column and SQLite keeps whole (`2026-08-27 00:00:00`). A literal that
     * matched one would miss the other, and missing it here means publishing a second issue for a
     * day that already has one.
     */
    public function publishedOn(CarbonImmutable $day): ?HomeIssue
    {
        return HomeIssue::query()->whereDate('issue_date', $day->toDateString())->first();
    }

    /**
     * What the issue would hold. Null is the 休刊 rule ({@see HomeIssuePlan::isBlank()}): a day on
     * which nothing was born gets no issue rather than an empty one.
     */
    public function plan(CarbonImmutable $now, ?SourceRef $pin = null, ?HomeIssueWindow $window = null): ?HomeIssuePlan
    {
        $window ??= $this->window($now);

        $stories = $this->stories($window);
        $lead = $pin === null ? null : $this->lead($pin);

        if ($lead !== null) {
            // Dropping the duplicate is what keeps the (issue, section, source, id) unique out of
            // this: an operator pinning the item the algorithm already chose is the ordinary case,
            // not a mistake.
            $stories = array_values(array_filter(
                $stories,
                fn (PlannedItem $item): bool => $item->ref()->key() !== $lead->ref()->key(),
            ));
            array_unshift($stories, $lead);
            $stories = array_slice($stories, 0, HomeIssueSection::Stories->cap());
        }

        $plan = new HomeIssuePlan(
            $window->lastDay()->toDateString(),
            $window,
            [
                HomeIssueSection::Stories->value => $stories,
                HomeIssueSection::Talk->value => $this->section(
                    HomeIssueSection::Talk,
                    $this->talkBursts->alias(),
                    fn (int $limit): array => ($this->talkBursts)($window, $limit)->all(),
                ),
                HomeIssueSection::Newcomers->value => $this->section(
                    HomeIssueSection::Newcomers,
                    $this->newcomers->alias(),
                    fn (int $limit): array => ($this->newcomers)($window, $limit)->all(),
                ),
                HomeIssueSection::NewGroups->value => $this->section(
                    HomeIssueSection::NewGroups,
                    $this->newGroups->alias(),
                    fn (int $limit): array => ($this->newGroups)($window, $limit)->all(),
                ),
                HomeIssueSection::UpcomingEvents->value => $this->section(
                    HomeIssueSection::UpcomingEvents,
                    $this->upcomingEvents->alias(),
                    fn (int $limit): array => ($this->upcomingEvents)($now, $limit)->all(),
                ),
            ],
            $pin !== null && $lead === null ? $pin : null,
        );

        return $plan->isBlank() ? null : $plan;
    }

    /**
     * The stretch this issue covers: the previous issue's `published_at` (exclusive) to now
     * (inclusive), so an issue that ran late still covers exactly what the one before it did not.
     *
     * A run by hand mid-afternoon therefore takes that day's issue early, and the next window simply
     * opens on the instant it closed. Nothing goes unreported: the run at the next 06:00 finds the
     * day already published and writes nothing, and the one after it covers everything from the
     * manual run onwards.
     */
    public function window(CarbonImmutable $now): HomeIssueWindow
    {
        $previous = ($this->latestIssue)();

        return new HomeIssueWindow(
            $previous === null ? $now->subDays(self::FIRST_WINDOW_DAYS) : CarbonImmutable::parse($previous->published_at),
            $now,
        );
    }

    /**
     * The number the next issue would carry.
     *
     * The read is locking so two runs cannot read the same maximum, which is the whole of the
     * serialization: MySQL holds it to the end of the enclosing transaction, SQLite compiles the
     * clause away and refuses the loser's write instead ({@see __invoke} retries). Called outside a
     * transaction it is a prediction — which is all a dry run needs.
     */
    public function nextNumber(): int
    {
        return (int) HomeIssue::query()->lockForUpdate()->max('number') + 1;
    }

    /**
     * The four story kinds, merged and cut to the section's cap.
     *
     * Each kind is asked for the whole cap so the merged top-N is exact — asking for a share each
     * would cap a quiet day's best story out for a busier kind's eighth.
     *
     * @return list<PlannedItem>
     */
    private function stories(HomeIssueWindow $window): array
    {
        $cap = HomeIssueSection::Stories->cap();
        $items = [];

        foreach ($this->storyQueries() as $query) {
            if (! $this->unitOn(HomeIssueSection::Stories, $query->alias())) {
                continue;
            }

            foreach ($query($window, $cap) as $item) {
                $items[] = $item;
            }
        }

        // Sorting is stable in PHP, so equal-in-every-key items keep the order the kinds were walked
        // in rather than an arbitrary one.
        usort($items, fn (PlannedItem $a, PlannedItem $b): int => [
            $b->score, $b->createdAt->getTimestamp(), $b->sourceId,
        ] <=> [
            $a->score, $a->createdAt->getTimestamp(), $a->sourceId,
        ]);

        return array_slice($items, 0, $cap);
    }

    /**
     * A single-source section, or nothing at all when its unit is switched off — the query is not
     * run rather than run and discarded, mirroring the dashboard (HomeController::dashboard).
     *
     * @param  callable(int): list<PlannedItem>  $candidates
     * @return list<PlannedItem>
     */
    private function section(HomeIssueSection $section, string $alias, callable $candidates): array
    {
        return $this->unitOn($section, $alias) ? $candidates($section->cap()) : [];
    }

    /** The pinned story, or null if it is not one this issue may carry. */
    private function lead(SourceRef $pin): ?PlannedItem
    {
        if (! HomeIssueSection::Stories->allowsSource($pin->type) || ! $this->unitOn(HomeIssueSection::Stories, $pin->type)) {
            return null;
        }

        foreach ($this->storyQueries() as $query) {
            if ($query->alias() === $pin->type) {
                return $query->find($pin->id);
            }
        }

        return null;
    }

    private function unitOn(HomeIssueSection $section, string $alias): bool
    {
        $unit = $section->unit($alias);

        return $unit === null || $unit->enabled();
    }

    /** @return list<StoryCandidates> */
    private function storyQueries(): array
    {
        return [$this->timelineStories, $this->diaryStories, $this->topicStories, $this->eventStories];
    }

    /** The issue and its ledger, in one transaction — an issue with half a ledger is not an issue. */
    private function write(HomeIssuePlan $plan): HomeIssue
    {
        $issue = HomeIssue::create([
            'number' => $this->nextNumber(),
            'issue_date' => $plan->issueDate,
            'window_start' => $plan->window->start,
            'published_at' => $plan->window->end,
        ]);

        foreach (HomeIssueSection::cases() as $section) {
            $rank = 0;

            foreach ($plan->items($section) as $item) {
                $issue->items()->create([
                    'section' => $section,
                    'rank' => ++$rank,
                    'source_type' => $item->sourceType,
                    'source_id' => $item->sourceId,
                    'score' => $item->score,
                    'stats' => $item->stats,
                ]);
            }
        }

        return $issue;
    }
}
