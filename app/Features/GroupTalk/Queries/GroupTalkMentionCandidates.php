<?php

namespace App\Features\GroupTalk\Queries;

use App\Features\Block\BlockLookup;
use App\Features\GroupTalk\TalkBody;
use App\Models\Group;
use App\Models\Member;
use App\Support\Database\SearchCollation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What this offers has to be exactly what `ResolveMentions` accepts for the same group — same
 * membership, same ban and block rules — or the picker would suggest names the submit drops.
 */
class GroupTalkMentionCandidates
{
    public const LIMIT = 8;

    /** A handle is "@" plus the name, and the body caps at TalkBody::MAX, so a longer name could be picked but never posted. */
    public const MAX_NAME = TalkBody::MAX - 1;

    /** @return Collection<int, Member> */
    public function __invoke(Member $viewer, Group $group, string $q): Collection
    {
        $term = trim($q);

        // Code points, to match the body cap the name must fit into — sqlite's LENGTH counts them;
        // MySQL's counts bytes and needs CHAR_LENGTH.
        $length = DB::connection()->getDriverName() === 'sqlite' ? 'LENGTH' : 'CHAR_LENGTH';

        $query = Member::query()
            ->whereIn('members.id', DB::table('group_members')
                ->where('group_id', $group->getKey())
                ->select('member_id'))
            ->with('avatar.file')
            ->whereKeyNot($viewer->getKey())
            ->where('members.is_login_rejected', false)
            ->tap(fn ($q) => SearchCollation::whereContains($q, 'members.name', $term))
            ->whereRaw("{$length}(members.name) <= ?", [self::MAX_NAME])
            // The id breaks ties, so members sharing a name keep a fixed order under the limit.
            ->tap(fn ($q) => SearchCollation::orderBy($q, 'members.name'))
            ->orderBy('members.id');

        BlockLookup::excludeBlockedBetween($query, $viewer, 'members.id');

        return $query->limit(self::LIMIT)->get();
    }
}
