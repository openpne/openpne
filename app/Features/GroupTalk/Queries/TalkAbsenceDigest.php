<?php

namespace App\Features\GroupTalk\Queries;

use App\Features\GroupTalk\GroupTalkCursor;
use App\Features\GroupTalk\Serializers\GroupMessageSerializer;
use App\Features\GroupTalk\UnreadTalkScope;
use App\Features\Member\Serializers\MemberRefSerializer;
use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\Member;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * What a member missed while they were away, for the catch-up card the talk page draws at the unread
 * boundary: how much was said, from when, by whom, and a glimpse of what was posted.
 *
 * Only for a backlog large enough to be worth summarizing. Under {@see THRESHOLD} the reader can
 * simply scroll it, and this runs no query at all — the page ships no digest prop rather than an
 * empty one, so the absence is structural instead of a number the client has to compare again.
 *
 * The count is the snapshot's, never a recount: the card and the divider beside it name the same
 * backlog, and a second count taken a moment later would disagree with the line the reader opened on.
 */
class TalkAbsenceDigest
{
    /** The backlog at which scrolling stops being the answer, and the card appears. */
    public const THRESHOLD = 10;

    /**
     * How many unread messages the card is described FROM, however many are waiting. A digest of an
     * unbounded backlog would read the whole of a room a member has been away from for a month.
     */
    public const SAMPLE = 50;

    /** Faces on the card. */
    public const PARTICIPANTS = 5;

    /** Pictures on the card. */
    public const THUMBNAILS = 3;

    /**
     * @param  array{count: int, at: CarbonImmutable, id: int}|null  $snapshot  the boundary the page rendered with
     *                                                                          ({@see TalkUnreadSnapshot}); null for a reader who holds no cursor
     * @return array{count: int, since: string, participants: list<array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool}>, thumbnails: list<array{id: int, url: string, thumbnailUrl: string, fitSources: list<array{url: string, box: int}>, cropSources: array{tall?: list<array{url: string, width: int}>, wide?: list<array{url: string, width: int}>}, width: int|null, height: int|null}>}|null
     */
    public function __invoke(Group $group, Member $viewer, ?array $snapshot): ?array
    {
        if ($snapshot === null || $snapshot['count'] < self::THRESHOLD) {
            return null;
        }

        $sample = $this->sample($group, $viewer, new GroupTalkCursor($snapshot['at'], $snapshot['id']));

        return [
            'count' => $snapshot['count'],
            'since' => GroupMessageSerializer::instant($snapshot['at']),
            'participants' => $this->participants($sample),
            'thumbnails' => $this->thumbnails($viewer, $sample),
        ];
    }

    /**
     * The first {@see SAMPLE} unread messages from the boundary, oldest first.
     *
     * A bounded read by contract, and the bound is visible in what the card says: the faces and the
     * pictures describe THIS window, not the whole backlog. A member returning to a thousand-message
     * room gets the first fifty summarized — the ones the boundary opens on, which is where they are
     * about to start reading — rather than an aggregate over the lot.
     *
     * @return Collection<int, GroupMessage>
     */
    private function sample(Group $group, Member $viewer, GroupTalkCursor $boundary): Collection
    {
        return UnreadTalkScope::since(
            GroupMessage::query()
                ->where('group_id', $group->getKey())
                // The card draws a face per author and a thumbnail per picture; without these the
                // sample would cost a query per row it summarizes.
                ->with(['author.avatar.file', 'images.file']),
            $boundary,
            (int) $viewer->getKey(),
        )
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(self::SAMPLE)
            ->get();
    }

    /**
     * Who did the talking, busiest first, ties broken by who spoke first — grouped here rather than
     * in SQL because the rows are already in hand and a GROUP BY would have to read past the sample
     * to be worth its own query.
     *
     * A withdrawn author is skipped: there is no member to draw a face for, and a blank one in a row
     * of faces reads as somebody rather than as nobody.
     *
     * @param  Collection<int, GroupMessage>  $sample
     * @return list<array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool}>
     */
    private function participants(Collection $sample): array
    {
        /** @var array<int, array{author: Member, said: int}> $spoke keyed by member id, in first-appearance order */
        $spoke = [];

        foreach ($sample as $message) {
            $author = $message->author;

            if ($author === null) {
                continue;
            }

            $id = (int) $author->getKey();
            $spoke[$id] ??= ['author' => $author, 'said' => 0];
            $spoke[$id]['said']++;
        }

        // PHP's sorts have been stable since 8.0, so equal counts keep the insertion order above —
        // which is first appearance. That is the tie-break, not an accident of it.
        uasort($spoke, fn (array $a, array $b): int => $b['said'] <=> $a['said']);

        return array_map(
            fn (array $entry): array => MemberRefSerializer::ref($entry['author']),
            array_slice(array_values($spoke), 0, self::PARTICIPANTS),
        );
    }

    /**
     * A glimpse of what was posted: the first {@see THUMBNAILS} pictures of the sample, in message
     * order and slot order within a message.
     *
     * Every candidate passes two gates. The join row names a file, but only the file names its owner,
     * so a row whose file belongs to some other parent is refused as not this message's picture —
     * it might well pass the policy on its own owner's terms. Then the policy that guards the bytes
     * (FilePolicy) is asked per file, the same one the delivery route asks. A refusal, or a file that
     * is no longer there, is skipped in silence: no placeholder, no gap, nothing in the payload
     * saying a picture was left out.
     *
     * Stops at the cap rather than shaping everything and slicing, so the policy runs for the
     * pictures the card shows — and never for a parent outside the sample.
     *
     * @param  Collection<int, GroupMessage>  $sample
     * @return list<array{id: int, url: string, thumbnailUrl: string, fitSources: list<array{url: string, box: int}>, cropSources: array{tall?: list<array{url: string, width: int}>, wide?: list<array{url: string, width: int}>}, width: int|null, height: int|null}>
     */
    private function thumbnails(Member $viewer, Collection $sample): array
    {
        $shown = [];

        foreach ($sample as $message) {
            foreach ($message->images as $image) {
                $file = $image->file;

                if ($file === null || (int) $file->related_entity_id !== (int) $message->getKey()) {
                    continue;
                }

                // instanceof rather than a string match, so the legacy morph aliases resolve too.
                $ownerClass = Relation::getMorphedModel($file->related_entity_type ?? '');
                if ($ownerClass === null || ! $message instanceof $ownerClass) {
                    continue;
                }

                if (! Gate::forUser($viewer)->allows('view', $file)) {
                    continue;
                }

                $shown[] = GroupMessageSerializer::image($image);

                if (count($shown) === self::THUMBNAILS) {
                    return $shown;
                }
            }
        }

        return $shown;
    }
}
