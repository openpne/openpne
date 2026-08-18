<?php

namespace App\Features\GroupTalk\Queries;

use App\Models\Group;
use App\Models\GroupMessage;
use Illuminate\Support\Collection;

/**
 * The messages a page's replies answer, in one read.
 *
 * A reply carries an id and nothing else — no snapshot of what it answers — so the header a client
 * draws is read from the parent row every time, and a parent that has been deleted simply has no row
 * to return. That absence is the placeholder state, which is why the map keys what was found rather
 * than reporting what was missing.
 *
 * **The lookup is bound to the group**, so an id belonging to another conversation comes back missing
 * and reads as deleted. Structural, not a defensive branch: no eager-load path off the model may be
 * used for this, since an unscoped one would answer with the foreign row.
 */
class ReplyReferences
{
    /**
     * What the Modern surface draws off a parent — its author's byline and the picture it leads with.
     *
     * @var list<string>
     */
    public const WITH = ['author.avatar.file', 'images.file'];

    /**
     * @param  Collection<int, GroupMessage>  $messages
     * @param  list<string>  $with  relations the caller's shape reads off a parent. The MCP wire names
     *                              none: it carries the author's id, which is a column of the parent row
     * @return array<int, GroupMessage> parents by id; an id that is not a live message of $group has no
     *                                  key at all
     */
    public function __invoke(Group $group, Collection $messages, array $with = self::WITH): array
    {
        $ids = $messages
            ->map(fn (GroupMessage $message): ?int => $message->in_reply_to_id === null ? null : (int) $message->in_reply_to_id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        return GroupMessage::query()
            ->where('group_id', $group->getKey())
            ->whereIn('id', $ids)
            ->with($with)
            ->get()
            ->keyBy(fn (GroupMessage $parent): int => (int) $parent->getKey())
            ->all();
    }

    /**
     * One message's own reference, for a write that answers with the row it just stored.
     *
     * @param  list<string>  $with
     * @return array<int, GroupMessage>
     */
    public function of(Group $group, GroupMessage $message, array $with = self::WITH): array
    {
        return $this($group, new Collection([$message]), $with);
    }
}
