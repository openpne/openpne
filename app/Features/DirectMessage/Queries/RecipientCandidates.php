<?php

namespace App\Features\DirectMessage\Queries;

use App\Features\Block\BlockLookup;
use App\Models\Member;
use App\Support\Feature;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Who the new-message picker may offer: the viewer's friends first, then anyone else whose name
 * matches the term, capped at LIMIT.
 *
 * A blank term stops at the friends. Unlike the mention picker's, this list is what the screen opens
 * on, and the question it answers is "who do I write to" — the site's roster in name order is no
 * answer to that, and searching is what reaches the rest of the SNS.
 *
 * This is the **first** of the two gates a message passes: it restates
 * DirectMessageAccess::canSend as a filter (not yourself, not a banned member, no block in either
 * direction), so a name offered here is one the conversation will have a composer for. The send asks
 * the same question again, because a block can arrive between the two.
 */
class RecipientCandidates
{
    /** A screenful of rows, where the mention picker's eight sit in a popup over a body field. */
    public const LIMIT = 20;

    /** The LIKE escape character. Not a backslash: MySQL and SQLite read one inside ESCAPE differently. */
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
        // Leading with friends is a friend lens, so it goes with the unit; the all-member tier below
        // answers the same question without it (docs/internals/feature-toggles.md).
        if (! Feature::Friend->enabled()) {
            return Collection::empty();
        }

        $friends = $viewer->friendships();
        $this->constrain($friends->getQuery(), $viewer, $pattern);

        return $friends->limit(self::LIMIT)->get();
    }

    /**
     * The rest of the SNS, filling what the friend tier left. The friends already returned are
     * excluded by id, so a friend cannot appear twice across the two queries.
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
     * offered. Ordered by (name, id): the name is what the picker reads, the id keeps members
     * sharing one name in a fixed order.
     *
     * There is no length bound on the name. The mention picker has one because a handle has to fit
     * inside a body; a recipient is a member id and carries no text into the message.
     *
     * @param  Builder<Member>  $query
     */
    private function constrain(Builder $query, Member $viewer, string $pattern): void
    {
        $query
            // The ref the endpoint serializes draws an avatar, so a tier costs one avatar query, not one per row.
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
