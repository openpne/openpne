<?php

namespace App\Features\GroupTalk\Actions;

use App\Features\GroupTalk\Exceptions\GroupTalkActionException;
use App\Features\GroupTalk\Exceptions\GroupTalkActionFailure;
use App\Features\GroupTalk\GroupTalkRoomNotificationRows;
use App\Features\GroupTalk\TalkReadCursor;
use App\Features\Notifications\ConsumeNotificationRows;
use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\Member;
use App\Notifications\GroupTalk\GroupTalkMentionedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\DatabaseNotification;

class MarkTalkRead
{
    public function __construct(
        private readonly GroupTalkRoomNotificationRows $rows,
        private readonly ConsumeNotificationRows $feedRows,
    ) {}

    /**
     * A null $messageId means "read through the latest", which the server resolves here rather than
     * taking one the client fetched (docs/internals/group-talk.md, "Mark-read is client-named,
     * server-resolved, and monotonic").
     *
     * @throws GroupTalkActionException
     */
    public function __invoke(Member $member, Group $group, ?int $messageId): void
    {
        $groupId = (int) $group->getKey();
        $memberId = (int) $member->getKey();

        if (! TalkReadCursor::exists($groupId, $memberId)) {
            throw new GroupTalkActionException(GroupTalkActionFailure::NotMember);
        }

        // Unconditional and ahead of the cursor move: a call that advances nothing, because another
        // tab moved the cursor first, still means the room has been seen.
        $this->rows->markRead($memberId, $groupId);

        if ($messageId === null) {
            $latest = TalkReadCursor::snapshot($groupId);
            TalkReadCursor::advance($groupId, $memberId, $latest['talk_read_at'], $latest['talk_read_message_id']);
        } else {
            $message = GroupMessage::query()
                ->where('group_id', $groupId)
                ->whereKey($messageId)
                ->first(['id', 'created_at']);

            if ($message === null) {
                throw new GroupTalkActionException(GroupTalkActionFailure::UnknownMessage);
            }

            TalkReadCursor::advance($groupId, $memberId, CarbonImmutable::instance($message->created_at), (int) $message->getKey());
        }

        $this->markMentionsRead($groupId, $memberId);
    }

    /**
     * The position is read back from the membership rather than taken from this call, since the
     * advance is forward-only and may have moved nothing. A message deleted since has no position to
     * compare and keeps its row.
     */
    private function markMentionsRead(int $groupId, int $memberId): void
    {
        $rows = $this->feedRows->unreadRows($memberId, GroupTalkMentionedNotification::class)
            ->filter(static fn (DatabaseNotification $row): bool => (int) ($row->data['group_id'] ?? 0) === $groupId);

        if ($rows->isEmpty()) {
            return;
        }

        $messages = GroupMessage::query()
            ->whereKey($rows->map(self::mentionedMessageId(...))->all())
            ->get(['id', 'created_at'])
            ->keyBy(static fn (GroupMessage $message): int => (int) $message->getKey());

        $ids = $rows
            ->filter(static function (DatabaseNotification $row) use ($messages, $groupId, $memberId): bool {
                $message = $messages->get(self::mentionedMessageId($row));

                return $message !== null && ! TalkReadCursor::isBehind(
                    $groupId,
                    $memberId,
                    CarbonImmutable::instance($message->created_at),
                    (int) $message->getKey(),
                );
            })
            ->map(static fn (DatabaseNotification $row): string => $row->getKey())
            ->all();

        $this->feedRows->markRead(...$ids);
    }

    private static function mentionedMessageId(DatabaseNotification $row): int
    {
        return (int) ($row->data['message_id'] ?? 0);
    }
}
