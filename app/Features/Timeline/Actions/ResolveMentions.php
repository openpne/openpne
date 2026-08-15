<?php

namespace App\Features\Timeline\Actions;

use App\Features\Block\BlockLookup;
use App\Models\Group;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

/**
 * Turn a compose form's mention payload into timeline_post_mentions rows.
 *
 * A row that no longer describes reality is dropped on its own and the post goes through: the
 * picker's selection can go stale between choosing a member and submitting (a rename, a fresh
 * block), and losing the whole message over a decoration nobody re-checked would be the wrong
 * trade. A dropped row simply leaves that range as the plain text it already is. Structural
 * nonsense never reaches here — App\Http\Requests\Concerns\MentionRules rejects the request.
 */
class ResolveMentions
{
    /**
     * @param  list<array{member_id: int, offset: int, length: int}>  $payload
     * @param  ?Group  $group  the group the message belongs to, if any — mentionability narrows
     *                         to its members there (group talk; the timeline itself is SNS-wide)
     * @return list<array{member_id: int, offset: int, length: int}> ascending by offset, non-overlapping
     */
    public function __invoke(Member $author, string $body, array $payload, ?Group $group = null): array
    {
        if ($payload === []) {
            return [];
        }

        $names = $this->mentionableNames($author, array_column($payload, 'member_id'), $group);
        $bodyLength = mb_strlen($body);

        usort($payload, fn (array $a, array $b): int => $a['offset'] <=> $b['offset']);

        $resolved = [];
        $consumed = 0; // one past the end of the last accepted range

        foreach ($payload as $mention) {
            // Ranges are stored as a partition of the body, so a mention may neither run off the end
            // nor reach back into one already accepted (which also keeps two rows off one offset,
            // as the unique index requires).
            if ($mention['offset'] < $consumed || $bodyLength < $mention['offset'] + $mention['length']) {
                continue;
            }
            // The range must still read as the member's handle. This is the one check that enforces
            // mentionability too: a member the query above excluded — gone, banned, blocked, the
            // author — has no name here to match against.
            $name = $names[$mention['member_id']] ?? null;
            if ($name === null || mb_substr($body, $mention['offset'], $mention['length']) !== '@'.$name) {
                continue;
            }

            $resolved[] = $mention;
            $consumed = $mention['offset'] + $mention['length'];
        }

        return $resolved;
    }

    /**
     * Whether $author may mention this one member — the same gate resolution applies, asked before
     * there is a body to resolve. A composer that writes the handle itself (the MCP reply tool) has
     * to know the answer to decide whether to write it at all, and asking the query rather than
     * restating its conditions is what keeps the pre-check and the write from disagreeing.
     *
     * False for $author themselves, since nobody mentions themselves.
     */
    public function isMentionable(Member $author, int $targetId, ?Group $group = null): bool
    {
        return isset($this->mentionableNames($author, [$targetId], $group)[$targetId]);
    }

    /**
     * The distinct members a resolved set names, which is the audience the mention notification
     * addresses — one member mentioned twice in a body is still one recipient.
     *
     * @param  list<array{member_id: int, offset: int, length: int}>  $resolved
     * @return list<int>
     */
    public static function memberIds(array $resolved): array
    {
        return array_values(array_unique(array_column($resolved, 'member_id')));
    }

    /**
     * The names, keyed by id, of the payload's members the author may mention: existing, not banned,
     * not the author, and with no block in either direction. One query, so ten mentions cost what
     * one does.
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function mentionableNames(Member $author, array $ids, ?Group $group = null): array
    {
        $query = Member::query()
            ->whereIn('id', $ids)
            ->whereKeyNot($author->getKey())
            ->where('is_login_rejected', false)
            // Callers resolve inside the post's insert transaction; the share lock holds each
            // matched member in place until the mention rows are in, so a resolved id cannot
            // vanish before its FK insert. A no-op on sqlite, whose writes serialize anyway.
            ->sharedLock();

        // Inside a group, only its members are mentionable — the same set
        // GroupTalkMentionCandidates offers, so the picker never shows a name the submit would drop.
        if ($group !== null) {
            $query->whereIn('members.id', DB::table('group_members')
                ->where('group_id', $group->getKey())
                ->select('member_id'));
        }

        BlockLookup::excludeBlockedBetween($query, $author, 'members.id');

        return $query->pluck('name', 'id')->all();
    }
}
