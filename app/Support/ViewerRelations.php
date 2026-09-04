<?php

declare(strict_types=1);

namespace App\Support;

use App\Features\Group\GroupRole;
use App\Models\Group;
use App\Models\Member;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A memo of pairs read in bulk for one page, never a picture of a reader: a pair not read answers
 * null, and the rule then runs its own single-row query, which must agree with the bulk read. A
 * write to any of these relations calls {@see flush()}; nothing here re-reads on its own.
 */
final class ViewerRelations
{
    /** @var array<string, bool> "viewer:owner" → whether the owner blocks the viewer */
    private array $blocks = [];

    /** @var array<string, bool> "viewer:other" → whether the two are friends */
    private array $friends = [];

    /** @var array<string, GroupRole|null> "viewer:group" → the viewer's role there, null for none */
    private array $roles = [];

    /** Forget every memoised answer, so a write to these relations is not answered from before it. */
    public static function flush(): void
    {
        app(self::class)->reset();
    }

    /**
     * Whether $owner blocks $viewer, or null when that pair has not been read.
     *
     * Null is "ask as you always have", not "no": {@see BlockLookup::ownerBlocksViewer()}.
     */
    public function ownerBlocksViewer(Member $owner, Member $viewer): ?bool
    {
        $key = $this->key($viewer, $owner);

        // array_key_exists, not `??`: a pair read and found unrelated is stored as false and must not
        // read as unread.
        return array_key_exists($key, $this->blocks) ? $this->blocks[$key] : null;
    }

    /** Whether $viewer and $other are friends, or null when that pair has not been read. */
    public function isFriend(Member $viewer, Member $other): ?bool
    {
        $key = $this->key($viewer, $other);

        return array_key_exists($key, $this->friends) ? $this->friends[$key] : null;
    }

    /**
     * Whether {@see roleIn()} can answer for this pair.
     *
     * Asked separately because null is a real answer there — "in no group of that id" — where for
     * the two above it can only mean "not read".
     */
    public function knowsRole(Group $group, Member $viewer): bool
    {
        return array_key_exists($this->key($viewer, $group), $this->roles);
    }

    /** $viewer's role in $group, or null when they are not in it. Ask {@see knowsRole()} first. */
    public function roleIn(Group $group, Member $viewer): ?GroupRole
    {
        $key = $this->key($viewer, $group);

        return array_key_exists($key, $this->roles) ? $this->roles[$key] : null;
    }

    /**
     * Read in one query which of $ownerIds block $viewer.
     *
     * The ids not returned are memoised as "does not block" — that is the whole point of asking in
     * bulk, and it is sound because the question was asked of exactly this set.
     *
     * @param  list<int|string|null>  $ownerIds
     */
    public function warmBlocks(Member $viewer, array $ownerIds): void
    {
        $ids = $this->ids($ownerIds);

        if ($ids === []) {
            return;
        }

        $blocking = DB::table('member_blocks')
            ->where('blocked_id', $viewer->getKey())
            ->whereIn('blocker_id', $ids)
            ->pluck('blocker_id');

        $this->fill($this->blocks, $viewer, $ids, $this->set($blocking));
    }

    /**
     * Read in one query which of $otherIds are friends of $viewer.
     *
     * Anchored on the viewer's own half of the mirror, as `Member::isFriendsWith` is.
     *
     * @param  list<int|string|null>  $otherIds
     */
    public function warmFriends(Member $viewer, array $otherIds): void
    {
        $ids = $this->ids($otherIds);

        if ($ids === []) {
            return;
        }

        $friends = DB::table('friendships')
            ->where('member_id', $viewer->getKey())
            ->whereIn('friend_id', $ids)
            ->pluck('friend_id');

        $this->fill($this->friends, $viewer, $ids, $this->set($friends));
    }

    /**
     * Read in one query what $viewer is to each of $groupIds.
     *
     * A group they are not in is memoised as no role, which is the answer `GroupMembership::roleOf`
     * gives it.
     *
     * @param  list<int|string|null>  $groupIds
     */
    public function warmRoles(Member $viewer, array $groupIds): void
    {
        $ids = $this->ids($groupIds);

        if ($ids === []) {
            return;
        }

        $roles = DB::table('group_members')
            ->where('member_id', $viewer->getKey())
            ->whereIn('group_id', $ids)
            ->pluck('role', 'group_id');

        foreach ($ids as $id) {
            $role = $roles[$id] ?? null;
            $this->roles[$viewer->getKey().':'.$id] = $role === null ? null : GroupRole::from((int) $role);
        }
    }

    private function reset(): void
    {
        $this->blocks = [];
        $this->friends = [];
        $this->roles = [];
    }

    private function key(Member $viewer, Member|Group $other): string
    {
        return $viewer->getKey().':'.$other->getKey();
    }

    /**
     * $ids as distinct positive integers — a page's records carry null owners (a withdrawn author)
     * and repeat them, and neither belongs in a `whereIn`.
     *
     * @param  list<int|string|null>  $ids
     * @return list<int>
     */
    private function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map(intval(...), $ids))));
    }

    /**
     * @param  Collection<int, mixed>  $ids
     * @return array<int, true>
     */
    private function set($ids): array
    {
        return array_fill_keys(array_map(intval(...), $ids->all()), true);
    }

    /**
     * @param  array<string, bool>  $memo
     * @param  list<int>  $ids
     * @param  array<int, true>  $hits
     */
    private function fill(array &$memo, Member $viewer, array $ids, array $hits): void
    {
        foreach ($ids as $id) {
            $memo[$viewer->getKey().':'.$id] = isset($hits[$id]);
        }
    }
}
