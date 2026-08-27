<?php

namespace App\Features\GroupTalk\Queries;

use App\Features\GroupTalk\GroupTalkCursor;
use App\Features\GroupTalk\Serializers\GroupMessageSerializer;
use App\Features\GroupTalk\UnreadTalkScope;
use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\Member;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

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
 *
 * Only the sample is this class's own — an unread backlog is bounded by a cursor rather than by a
 * period, so {@see TalkSampleDigest} cannot read it. What is said ABOUT the sample is that class's.
 */
class TalkAbsenceDigest
{
    /** The backlog at which scrolling stops being the answer, and the card appears. */
    public const THRESHOLD = 10;

    // The caps the card is drawn under are enforced by TalkSampleDigest, and named here too so the
    // card's contract stays readable from the class that ships the card.
    public const SAMPLE = TalkSampleDigest::SAMPLE;

    public const PARTICIPANTS = TalkSampleDigest::PARTICIPANTS;

    public const THUMBNAILS = TalkSampleDigest::THUMBNAILS;

    public const THUMBNAIL_CANDIDATES = TalkSampleDigest::THUMBNAIL_CANDIDATES;

    public function __construct(private readonly TalkSampleDigest $digest) {}

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
            'participants' => $this->digest->participants($sample),
            'thumbnails' => $this->digest->thumbnails($viewer, $sample),
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
                // The card draws a face per author; without this the sample would cost a query per
                // row it summarizes. Pictures are NOT loaded here — a parent does not bound its
                // attachment count, so they get their own capped read in thumbnails().
                ->with('author.avatar.file'),
            $boundary,
            (int) $viewer->getKey(),
        )
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(self::SAMPLE)
            ->get();
    }
}
