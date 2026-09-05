<?php

namespace App\Features\GroupTalk\Queries;

use App\Models\Group;
use App\Models\GroupMessage;
use Illuminate\Support\Collection;

/**
 * The lookup is bound to the group, so an id from another conversation comes back missing and reads
 * as deleted — and no `belongsTo` eager load may stand in for it, since an unscoped one would answer
 * with the foreign row. A parent that has been deleted simply has no key in the result, which is the
 * state the client draws.
 */
class ReplyReferences
{
    /** @var list<string> */
    public const WITH = ['author.avatar.file', 'images.file'];

    /**
     * @param  Collection<int, GroupMessage>  $messages
     * @param  list<string>  $with  relations the caller's shape reads off a parent; the MCP wire names
     *                              none
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
     * @param  list<string>  $with
     * @return array<int, GroupMessage>
     */
    public function of(Group $group, GroupMessage $message, array $with = self::WITH): array
    {
        return $this($group, new Collection([$message]), $with);
    }
}
