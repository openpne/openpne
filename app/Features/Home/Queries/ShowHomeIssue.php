<?php

declare(strict_types=1);

namespace App\Features\Home\Queries;

use App\Features\Home\Data\HydratedIssue;
use App\Features\Home\HomeItemGate;
use App\Models\Diary;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupTopic;
use App\Models\HomeIssue;
use App\Models\HomeIssueItem;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\ViewerRelations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * An issue read by one member: its ledger resolved back through every source's own gate.
 *
 * The whole page is bounded by the ledger, which is capped per section, so the reads are bounded
 * too: one per source table however many rows it holds, and the relations the gate asks about are
 * read in one query each ({@see ViewerRelations}) rather than per row. Talk is the exception by
 * design — a burst is a stretch of one room, and there is no read that describes several at once.
 */
final class ShowHomeIssue
{
    public function __construct(private readonly HomeItemGate $gate) {}

    public function __invoke(Member $viewer, HomeIssue $issue): HydratedIssue
    {
        /** @var EloquentCollection<int, HomeIssueItem> $items ordered by section, then rank */
        $items = $issue->items()->get();

        $sources = $this->sources($items);
        $this->warmRelations($viewer, $sources);

        $sections = [];

        foreach ($items as $item) {
            $source = $sources[(string) $item->source_type][(int) $item->source_id] ?? null;
            $resolved = $this->gate->resolve($viewer, $item, $source);

            if ($resolved !== null) {
                $sections[$item->section->value][] = $resolved;
            }
        }

        return new HydratedIssue($sections);
    }

    /**
     * Every source the ledger names, one read per table.
     *
     * Per table and not per (section, table): a group is featured both for being new and for what
     * was said in it, and reading it twice would buy nothing but a second query. The eager loads are
     * therefore the union of what the serializers of either section ask for — an event's roster
     * count is the calendar row's, not the story's.
     *
     * @param  EloquentCollection<int, HomeIssueItem>  $items
     * @return array<string, Collection<int, Model>> keyed by morph alias, then by id
     */
    private function sources(EloquentCollection $items): array
    {
        $loaders = $this->loaders();
        $sources = [];

        foreach ($items->groupBy(fn (HomeIssueItem $item): string => (string) $item->source_type) as $alias => $rows) {
            $load = $loaders[$alias] ?? null;

            // An alias no section holds, or one the morph map no longer knows: the gate drops the
            // row, and there is nothing to read for it here.
            if ($load === null) {
                continue;
            }

            $sources[$alias] = $load()
                ->whereKey($rows->pluck('source_id')->all())
                ->get()
                ->keyBy(fn (Model $model): int => (int) $model->getKey());
        }

        return $sources;
    }

    /**
     * The read per source table, keyed by the alias the ledger stores — taken from the model, so a
     * morph-map rename stays a morph-map edit.
     *
     * @return array<string, callable(): Builder<covariant Model>>
     */
    private function loaders(): array
    {
        return [
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
        ];
    }

    /**
     * The relations the gate asks about, read in one query each.
     *
     * Blocks and friendships only: every group arm answers from the group's own read column
     * (`topic_read_access`), so nothing here asks what the viewer is to a group.
     *
     * @param  array<string, Collection<int, Model>>  $sources
     */
    private function warmRelations(Member $viewer, array $sources): void
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
}
