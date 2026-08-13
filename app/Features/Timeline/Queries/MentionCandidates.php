<?php

namespace App\Features\Timeline\Queries;

use App\Features\Block\BlockLookup;
use App\Models\Member;
use App\Support\Feature;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who the compose form's @mention picker may offer for a search term: the viewer's friends first,
 * then anyone else whose name matches, capped at LIMIT. An empty term is the list the picker shows
 * the moment @ is typed, and needs no special case — it matches everyone, so the friend tier fills it.
 *
 * Whom it offers is ResolveMentions' mentionability, restated as a filter (not the author, not
 * banned, no block in either direction): a candidate the submit would silently drop must never be
 * offered in the first place.
 *
 * Two queries rather than one UNION: the friend tier joins the pivot, so both selects would have to
 * be padded to one column list for a result that is read in two tiers anyway.
 */
class MentionCandidates
{
    public const LIMIT = 8;

    /**
     * The longest name a mention can carry: the handle is "@" plus the name, and the body caps at
     * 140 code points. A longer name could be picked but never posted, so it is never offered.
     */
    public const MAX_NAME = 139;

    /**
     * The LIKE escape character. Not a backslash: MySQL and SQLite read a backslash inside the
     * ESCAPE literal differently, while `!` is one character to both.
     */
    private const ESCAPE = '!';

    /**
    @return Collection<int, Member> */
    public function __invoke(Member $viewer, string $q): Collection
    {
        $pattern = '%'.$this->escapeLike(trim($q)).'%';

        $friends = $this->friends($viewer, $pattern);

        return $friends->concat(
            $this->others($viewer, $pattern, $friends->modelKeys(), self::LIMIT - $friends->count())
        );
    }

    /** @return Collection<int, Member> */
    private function friends(Member $viewer, string $pattern): Collection
    {
        // Ranking friends first is a friend lens, so it goes with the unit; the all-member tier below
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
     * @param  Builder<Member>  $query
     */
    private function constrain(Builder $query, Member $viewer, string $pattern): void
    {
        // Code points, to match the body cap the name must fit into — sqlite's LENGTH counts them;
        // MySQL's counts bytes and needs CHAR_LENGTH.
        $length = DB::connection()->getDriverName() === 'sqlite' ? 'LENGTH' : 'CHAR_LENGTH';

        $query
            // The ref the endpoint serializes draws an avatar, so a tier costs one avatar query, not one per row.
            ->with('avatar.file')
            ->whereKeyNot($viewer->getKey())
            ->where('members.is_login_rejected', false)
            ->whereRaw('members.name LIKE ? ESCAPE ?', [$pattern, self::ESCAPE])
            ->whereRaw("{$length}(members.name) <= ?", [self::MAX_NAME])
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
