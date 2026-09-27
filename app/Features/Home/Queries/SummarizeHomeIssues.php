<?php

declare(strict_types=1);

namespace App\Features\Home\Queries;

use App\Features\GroupTalk\Queries\TalkSampleDigest;
use App\Features\Home\Data\HomeIssueSummary;
use App\Features\Home\Data\TalkStretch;
use App\Features\Home\HomeIssueSection;
use App\Features\Home\HomeItemGate;
use App\Models\GroupMessage;
use App\Models\HomeIssue;
use App\Models\HomeIssueItem;
use App\Models\Member;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A run of issues read by one member, each as much of it as a month draws. The reads do not grow
 * with the ledger; they grow with the issues that hold talk and with the talk pictures shown
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

        $items = $this->ledger($issues);

        $sources = $this->sources->forSummary($items);
        $this->sources->warmRelations($viewer, $sources);

        $admitted = $items
            ->map(fn (HomeIssueItem $item): array => [$item, $sources[(string) $item->source_type][(int) $item->source_id] ?? null])
            ->filter(fn (array $pair): bool => $this->gate->admits($viewer, $pair[0], $pair[1]))
            ->values();

        $stretches = $this->stretches($admitted);
        $rows = [];

        foreach ($admitted as [$item, $source]) {
            $section = $item->section === HomeIssueSection::Talk
                ? $stretches[(int) $item->getKey()] ?? null
                : $source;

            // An emptied stretch is nothing to report, which is what the issue page answers too.
            if ($section !== null) {
                $rows[(int) $item->home_issue_id][$item->section->value][] = $section;
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

        return $this->drawn($viewer, $summaries);
    }

    /**
     * Stories and talk at every rank, because a day counts what it does not show; names only as deep
     * as they are shown; the calendar not at all.
     *
     * @param  Collection<int, HomeIssue>  $issues
     * @return EloquentCollection<int, HomeIssueItem>
     */
    private function ledger(Collection $issues): EloquentCollection
    {
        return HomeIssueItem::query()
            ->whereIn('home_issue_id', $issues->map(fn (HomeIssue $issue): int => (int) $issue->getKey())->all())
            ->where(fn (Builder $depth) => $depth
                ->whereIn('section', [HomeIssueSection::Stories->value, HomeIssueSection::Talk->value])
                ->orWhere(fn (Builder $named) => $named
                    ->whereIn('section', [HomeIssueSection::Newcomers->value, HomeIssueSection::NewGroups->value])
                    ->where('rank', '<=', HomeIssueSummary::SHOWN)))
            ->orderBy('home_issue_id')
            ->orderBy('section')
            ->orderBy('rank')
            ->get();
    }

    /**
     * Bursts published together share their window, so the rooms of one issue are read at once, and
     * the messages they end on in one read for the whole run.
     *
     * @param  Collection<int, array{HomeIssueItem, Model}>  $admitted
     * @return array<int, TalkStretch> keyed by ledger row id, a room with nothing left being absent
     */
    private function stretches(Collection $admitted): array
    {
        $windows = [];

        foreach ($admitted as [$item, $source]) {
            if ($item->section !== HomeIssueSection::Talk) {
                continue;
            }

            [$since, $until] = $this->gate->window($item);
            $key = $since->toIso8601String().'|'.$until->toIso8601String();

            $windows[$key] ??= ['since' => $since, 'until' => $until, 'rows' => []];
            $windows[$key]['rows'][(int) $item->getKey()] = $source;
        }

        $read = [];

        foreach ($windows as $window) {
            $groups = array_values(array_unique(array_map(fn (Model $group): int => (int) $group->getKey(), $window['rows'])));
            $stretches = $this->talk->stretchesOf($groups, $window['since'], $window['until']);

            foreach ($window['rows'] as $row => $group) {
                $stretch = $stretches[(int) $group->getKey()] ?? null;

                if ($stretch !== null && $stretch['last'] !== null) {
                    $read[$row] = [$group, $stretch['count'], $stretch['last']];
                }
            }
        }

        if ($read === []) {
            return [];
        }

        $last = GroupMessage::query()
            ->whereKey(array_values(array_unique(array_column($read, 2))))
            ->get()
            ->keyBy(fn (GroupMessage $message): int => (int) $message->getKey());

        $stretches = [];

        foreach ($read as $row => [$group, $count, $id]) {
            // Gone between the two reads: the stretch is then reported by whoever reads it next.
            if ($last->has($id)) {
                $stretches[$row] = new TalkStretch($group, $count, $last[$id]);
            }
        }

        return $stretches;
    }

    /**
     * What is drawn is read for the items shown and no others, a relation at a time; a room's
     * picture is its message's first, and only when the per-file gate let it through.
     *
     * @param  array<int, HomeIssueSummary>  $summaries
     * @return array<int, HomeIssueSummary>
     */
    private function drawn(Member $viewer, array $summaries): array
    {
        $shown = collect($summaries)->flatMap(fn (HomeIssueSummary $summary): array => $summary->items());

        $talk = $shown->whereInstanceOf(TalkStretch::class);
        $said = $talk->map(fn (TalkStretch $stretch): GroupMessage => $stretch->last)->values();

        foreach ($shown->whereInstanceOf(Model::class)->groupBy(fn (Model $story): string => $story::class) as $stories) {
            EloquentCollection::make($stories->all())->load('images.file');
        }

        EloquentCollection::make($talk->map(fn (TalkStretch $stretch): Model => $stretch->group)->all())->unique()->load('image');
        EloquentCollection::make($said->all())->load('author');

        $pictures = $this->talk->firstPictures($viewer, $said);

        return array_map(
            fn (HomeIssueSummary $summary): HomeIssueSummary => $summary->withBursts(array_map(
                fn (TalkStretch $stretch): TalkStretch => $stretch->pictured($pictures[(int) $stretch->last->getKey()] ?? null),
                $summary->bursts,
            )),
            $summaries,
        );
    }
}
