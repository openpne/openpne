<?php

declare(strict_types=1);

namespace App\Features\Home\Queries;

use App\Features\GroupTalk\Queries\TalkSampleDigest;
use App\Features\Home\Data\HomeIssueSummary;
use App\Features\Home\HomeIssueSection;
use App\Features\Home\HomeItemGate;
use App\Models\Group;
use App\Models\HomeIssue;
use App\Models\HomeIssueItem;
use App\Models\Member;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A run of issues read by one member, each as what a month's row draws of it. The reads do not grow
 * with the run, a count per distinct burst window excepted
 * (docs/internals/home-issues.md, "The month page").
 */
final class SummarizeHomeIssues
{
    public function __construct(
        private readonly HomeItemGate $gate,
        private readonly HomeIssueSources $sources,
        private readonly TalkSampleDigest $talk,
    ) {}

    /**
     * @param  Collection<int, HomeIssue>  $issues
     * @return array<int, HomeIssueSummary> keyed by issue id, every issue given having an entry
     */
    public function __invoke(Member $viewer, Collection $issues): array
    {
        if ($issues->isEmpty()) {
            return [];
        }

        $items = HomeIssueItem::query()
            ->whereIn('home_issue_id', $issues->map(fn (HomeIssue $issue): int => (int) $issue->getKey())->all())
            ->where('section', '!=', HomeIssueSection::UpcomingEvents->value)
            ->orderBy('home_issue_id')
            ->orderBy('section')
            ->orderBy('rank')
            ->get();

        $sources = $this->sources->forSummary($items);
        $this->sources->warmRelations($viewer, $sources);

        $admitted = $items
            ->map(fn (HomeIssueItem $item): array => [$item, $sources[(string) $item->source_type][(int) $item->source_id] ?? null])
            ->filter(fn (array $pair): bool => $this->gate->admits($viewer, $pair[0], $pair[1]))
            ->values();

        $said = $this->said($admitted);
        $rows = [];

        foreach ($admitted as [$item, $source]) {
            $issue = (int) $item->home_issue_id;

            if ($item->section !== HomeIssueSection::Talk) {
                $rows[$issue][$item->section->value][] = $source;

                continue;
            }

            $count = $said[(int) $item->getKey()] ?? 0;

            // An emptied stretch is nothing to report, which is what the issue page answers too.
            if ($count > 0) {
                $rows[$issue][$item->section->value][] = ['group' => $source, 'count' => $count];
            }
        }

        $summaries = [];

        foreach ($issues as $issue) {
            $row = $rows[(int) $issue->getKey()] ?? [];

            $summaries[(int) $issue->getKey()] = new HomeIssueSummary(
                $row[HomeIssueSection::Stories->value] ?? [],
                $row[HomeIssueSection::Talk->value] ?? [],
                $row[HomeIssueSection::Newcomers->value] ?? [],
                $row[HomeIssueSection::NewGroups->value] ?? [],
            );
        }

        $this->picture($summaries);

        return $summaries;
    }

    /**
     * Bursts published together share their window, so the rooms of one issue are counted at once.
     *
     * @param  Collection<int, array{HomeIssueItem, Model}>  $admitted
     * @return array<int, int> messages still in the stretch, keyed by ledger row id
     */
    private function said(Collection $admitted): array
    {
        $stretches = [];

        foreach ($admitted as [$item, $source]) {
            if ($item->section !== HomeIssueSection::Talk) {
                continue;
            }

            [$since, $until] = $this->gate->window($item);
            $key = $since->toIso8601String().'|'.$until->toIso8601String();

            $stretches[$key] ??= ['since' => $since, 'until' => $until, 'rows' => []];
            $stretches[$key]['rows'][(int) $item->getKey()] = (int) $source->getKey();
        }

        $said = [];

        foreach ($stretches as $stretch) {
            $counts = $this->talk->countsBetween(
                array_values(array_unique($stretch['rows'])),
                $stretch['since'],
                $stretch['until'],
            );

            foreach ($stretch['rows'] as $row => $group) {
                $said[$row] = $counts[$group] ?? 0;
            }
        }

        return $said;
    }

    /**
     * Through the relation the issue page reads, so both draw the same picture of the same source.
     *
     * @param  array<int, HomeIssueSummary>  $summaries
     */
    private function picture(array $summaries): void
    {
        $tops = collect($summaries)
            ->map(fn (HomeIssueSummary $summary): ?Model => $summary->top())
            ->filter()
            ->groupBy(fn (Model $top): string => $top::class);

        foreach ($tops as $class => $models) {
            EloquentCollection::make($models->all())->unique()->load(match ($class) {
                Member::class => 'avatar.file',
                Group::class => 'image',
                default => 'images.file',
            });
        }
    }
}
