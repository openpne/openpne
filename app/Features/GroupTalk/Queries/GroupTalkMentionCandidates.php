<?php

namespace App\Features\GroupTalk\Queries;

use App\Features\Block\BlockLookup;
use App\Http\Requests\GroupTalk\StoreGroupMessageRequest;
use App\Models\Group;
use App\Models\Member;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who the talk composer's @mention picker may offer: **the group's own members**, and nobody else.
 * The mentionable set is the audience — a name from outside the group could not read the message the
 * mention appears in, so offering it would be offering a dead end.
 *
 * No friend tier, unlike the timeline's picker. There the candidate set is the whole SNS and friends
 * are the useful prefix of it; here the set is already the room, and ranking a friend above the
 * person you are talking to would order the list by the wrong relationship.
 *
 * What it offers is exactly what App\Features\Timeline\Actions\ResolveMentions accepts for the same
 * group — same membership, same ban and block rules — so a name the picker shows can never be
 * dropped by the submit. GroupTalkMentionCandidatesTest pins that equality.
 */
class GroupTalkMentionCandidates
{
    public const LIMIT = 8;

    /** A handle is "@" plus the name, and the body caps at MAX_BODY, so a longer name could be picked but never posted. */
    public const MAX_NAME = StoreGroupMessageRequest::MAX_BODY - 1;

    /** The LIKE escape character. Not a backslash: MySQL and SQLite read one inside ESCAPE differently. */
    private const ESCAPE = '!';

    /** @return Collection<int, Member> */
    public function __invoke(Member $viewer, Group $group, string $q): Collection
    {
        $pattern = '%'.$this->escapeLike(trim($q)).'%';

        // Code points, to match the body cap the name must fit into — sqlite's LENGTH counts them;
        // MySQL's counts bytes and needs CHAR_LENGTH.
        $length = DB::connection()->getDriverName() === 'sqlite' ? 'LENGTH' : 'CHAR_LENGTH';

        $query = Member::query()
            ->whereIn('members.id', DB::table('group_members')
                ->where('group_id', $group->getKey())
                ->select('member_id'))
            // The ref the endpoint serializes draws an avatar, so the page costs one avatar query, not one per row.
            ->with('avatar.file')
            ->whereKeyNot($viewer->getKey())
            ->where('members.is_login_rejected', false)
            ->whereRaw('members.name LIKE ? ESCAPE ?', [$pattern, self::ESCAPE])
            ->whereRaw("{$length}(members.name) <= ?", [self::MAX_NAME])
            // The name is what the picker reads; the id keeps members sharing one name in a fixed order.
            ->orderBy('members.name')
            ->orderBy('members.id');

        BlockLookup::excludeBlockedBetween($query, $viewer, 'members.id');

        return $query->limit(self::LIMIT)->get();
    }

    /** Neutralize the LIKE wildcards in the term: typing `%` looks for a member named `100%`, not for everyone. */
    private function escapeLike(string $term): string
    {
        return str_replace([self::ESCAPE, '%', '_'], [self::ESCAPE.self::ESCAPE, self::ESCAPE.'%', self::ESCAPE.'_'], $term);
    }
}
