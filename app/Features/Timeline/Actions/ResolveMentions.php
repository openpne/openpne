<?php

namespace App\Features\Timeline\Actions;

use App\Features\Block\BlockLookup;
use App\Models\Member;

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
     * @return list<array{member_id: int, offset: int, length: int}> ascending by offset, non-overlapping
     */
    public function __invoke(Member $author, string $body, array $payload): array
    {
        if ($payload === []) {
            return [];
        }

        $names = $this->mentionableNames($author, array_column($payload, 'member_id'));
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
     * The names, keyed by id, of the payload's members the author may mention: existing, not banned,
     * not the author, and with no block in either direction. One query, so ten mentions cost what
     * one does.
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function mentionableNames(Member $author, array $ids): array
    {
        $query = Member::query()
            ->whereIn('id', $ids)
            ->whereKeyNot($author->getKey())
            ->where('is_login_rejected', false)
            // Callers resolve inside the post's insert transaction; the share lock holds each
            // matched member in place until the mention rows are in, so a resolved id cannot
            // vanish before its FK insert. A no-op on sqlite, whose writes serialize anyway.
            ->sharedLock();

        BlockLookup::excludeBlockedBetween($query, $author, 'members.id');

        return $query->pluck('name', 'id')->all();
    }
}
