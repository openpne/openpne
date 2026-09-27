<?php

declare(strict_types=1);

namespace App\Features\Home\Queries;

use App\Models\Diary;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupTopic;
use App\Models\HomeIssueItem;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\ViewerRelations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The sources a run of ledger rows names, one read per table however many issues the rows span. Both
 * shapes load what the gate reads off a source; they differ in what is drawn afterwards.
 */
final class HomeIssueSources
{
    /**
     * One read per table and not per (section, table): a group is featured both for being new and
     * for what was said in it, so the eager loads are the union of what either section draws.
     *
     * @param  Collection<int, HomeIssueItem>  $items
     * @return array<string, Collection<int, Model>> keyed by morph alias, then by id
     */
    public function forPage(Collection $items): array
    {
        return $this->read($items, [
            (new TimelinePost)->getMorphClass() => fn (): Builder => TimelinePost::query()
                ->with(['member.avatar.file', 'images.file'])
                ->withCount('replies'),
            (new Diary)->getMorphClass() => fn (): Builder => Diary::query()
                ->with(['member.avatar.file', 'images.file'])
                ->withCount('comments'),
            (new GroupTopic)->getMorphClass() => fn (): Builder => GroupTopic::query()
                ->with(['member.avatar.file', 'group.image', 'images.file'])
                ->withCount('comments'),
            (new GroupEvent)->getMorphClass() => fn (): Builder => GroupEvent::query()
                ->with(['member.avatar.file', 'group.image', 'images.file'])
                ->withCount(['comments', 'participants']),
            (new Member)->getMorphClass() => fn (): Builder => Member::query()->with('avatar.file'),
            (new Group)->getMorphClass() => fn (): Builder => Group::query()->with('image'),
        ]);
    }

    /**
     * No pictures and no faces: a month draws one per issue, which its caller reads once the gate
     * has said which (docs/internals/home-issues.md, "The month page").
     *
     * @param  Collection<int, HomeIssueItem>  $items
     * @return array<string, Collection<int, Model>> keyed by morph alias, then by id
     */
    public function forSummary(Collection $items): array
    {
        return $this->read($items, [
            (new TimelinePost)->getMorphClass() => fn (): Builder => TimelinePost::query()->with('member')->withCount('replies'),
            (new Diary)->getMorphClass() => fn (): Builder => Diary::query()->with('member')->withCount('comments'),
            (new GroupTopic)->getMorphClass() => fn (): Builder => GroupTopic::query()->with('group')->withCount('comments'),
            (new GroupEvent)->getMorphClass() => fn (): Builder => GroupEvent::query()->with('group')->withCount('comments'),
            (new Member)->getMorphClass() => fn (): Builder => Member::query(),
            (new Group)->getMorphClass() => fn (): Builder => Group::query(),
        ]);
    }

    /**
     * The relations the gate asks about, read in one query each.
     *
     * Blocks and friendships only: every group arm answers from the group's own read column
     * (`topic_read_access`), so nothing here asks what the viewer is to a group.
     *
     * @param  array<string, Collection<int, Model>>  $sources
     */
    public function warmRelations(Member $viewer, array $sources): void
    {
        $relations = app(ViewerRelations::class);

        $owners = collect([
            ...($sources[(new TimelinePost)->getMorphClass()] ?? collect())->pluck('member_id')->all(),
            ...($sources[(new Diary)->getMorphClass()] ?? collect())->pluck('member_id')->all(),
        ]);

        // A newcomer is their own owner: MemberPolicy::access asks whether they block the reader.
        $blockable = [...$owners->all(), ...($sources[(new Member)->getMorphClass()] ?? collect())->keys()->all()];

        $relations->warmBlocks($viewer, $blockable);
        // Both story rules widen a viewer's clearance to Friends before comparing it, so the
        // friendship is asked for every author whether or not the answer can change the outcome.
        $relations->warmFriends($viewer, $owners->all());
    }

    /**
     * Keyed by the alias the ledger stores — taken from the model, so a morph-map rename stays a
     * morph-map edit.
     *
     * @param  Collection<int, HomeIssueItem>  $items
     * @param  array<string, callable(): Builder<covariant Model>>  $loaders
     * @return array<string, Collection<int, Model>>
     */
    private function read(Collection $items, array $loaders): array
    {
        $sources = [];

        foreach ($items->groupBy(fn (HomeIssueItem $item): string => (string) $item->source_type) as $alias => $rows) {
            $load = $loaders[$alias] ?? null;

            // An alias no section holds, or one the morph map no longer knows: the gate drops the
            // row, and there is nothing to read for it here.
            if ($load === null) {
                continue;
            }

            $sources[$alias] = $load()
                ->whereKey($rows->pluck('source_id')->unique()->all())
                ->get()
                ->keyBy(fn (Model $model): int => (int) $model->getKey());
        }

        return $sources;
    }
}
