<?php

namespace App\Features\DirectMessage\Queries;

use App\Features\Block\BlockLookup;
use App\Models\Member;
use App\Support\Feature;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The first of the two gates a message passes: it restates `DirectMessageAccess::canSend` as a
 * filter, and the send asks the same question again because a block can arrive between the two
 * (`docs/internals/direct-messages.md`, "Who a new one may be with").
 */
class RecipientCandidates
{
    public const LIMIT = 20;

    /** Not a backslash: MySQL and SQLite read one inside ESCAPE differently. */
    private const ESCAPE = '!';

    /** @return Collection<int, Member> */
    public function __invoke(Member $viewer, string $q): Collection
    {
        $term = trim($q);
        $pattern = '%'.$this->escapeLike($term).'%';

        $friends = $this->friends($viewer, $pattern);

        if ($term === '') {
            return $friends;
        }

        return $friends->concat(
            $this->others($viewer, $pattern, $friends->modelKeys(), self::LIMIT - $friends->count())
        );
    }

    /** @return Collection<int, Member> */
    private function friends(Member $viewer, string $pattern): Collection
    {
        // A friend tier is a friend lens and goes with the unit, the tier below answering the same
        // question without it (`docs/internals/feature-toggles.md`, "What a disabled unit does not change").
        if (! Feature::Friend->enabled()) {
            return Collection::empty();
        }

        $friends = $viewer->friendships();
        $this->constrain($friends->getQuery(), $viewer, $pattern);

        return $friends->limit(self::LIMIT)->get();
    }

    /**
     * The friends already returned are excluded by id, so a friend cannot appear twice across the
     * two queries.
     *
     * @param  list<int>  $exclude
     * @return Collection<int, Member>
     */
    private function others(Member $viewer, string $pattern, array $exclude, int $limit): Collection
    {
        if ($limit <= 0) {
            return Collection::empty();
        }

        $query = Member::query()->whereNotIn('members.id', $exclude);
        $this->constrain($query, $viewer, $pattern);

        return $query->limit($limit)->get();
    }

    /**
     * What both tiers share, so which tier a member falls in never decides whether they may be
     * offered. Ordered by `(name, id)`, the id keeping members who share one name in a fixed order.
     *
     * @param  Builder<Member>  $query
     */
    private function constrain(Builder $query, Member $viewer, string $pattern): void
    {
        $query
            ->with('avatar.file')
            ->whereKeyNot($viewer->getKey())
            ->where('members.is_login_rejected', false)
            ->whereRaw('members.name LIKE ? ESCAPE ?', [$pattern, self::ESCAPE])
            ->orderBy('members.name')
            ->orderBy('members.id');

        BlockLookup::excludeBlockedBetween($query, $viewer, 'members.id');
    }

    /** Neutralize the LIKE wildcards in the term: typing `%` looks for a member named `100%`, not for everyone. */
    private function escapeLike(string $term): string
    {
        return str_replace([self::ESCAPE, '%', '_'], [self::ESCAPE.self::ESCAPE, self::ESCAPE.'%', self::ESCAPE.'_'], $term);
    }
}
