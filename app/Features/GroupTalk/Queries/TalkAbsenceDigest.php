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
 * Under {@see THRESHOLD} no query runs and the page ships no digest prop at all, so the absence is
 * structural rather than an empty payload. The count is the snapshot's, never a recount: the card
 * and the divider beside it name one backlog.
 */
class TalkAbsenceDigest
{
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
     * A bounded read by contract: the faces and the pictures describe the window at the boundary,
     * never the whole backlog.
     *
     * @return Collection<int, GroupMessage>
     */
    private function sample(Group $group, Member $viewer, GroupTalkCursor $boundary): Collection
    {
        return UnreadTalkScope::since(
            GroupMessage::query()
                ->where('group_id', $group->getKey())
                // Pictures are deliberately not loaded here: a parent does not bound its attachment
                // count, so they get their own capped read in `thumbnails()`.
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
