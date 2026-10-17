<?php

namespace App\Features\GroupTalk\Actions;

use App\Features\GroupTalk\Events\GroupMessagePosted;
use App\Features\GroupTalk\Exceptions\GroupTalkActionException;
use App\Features\GroupTalk\Exceptions\GroupTalkActionFailure;
use App\Features\GroupTalk\GroupTalkAccess;
use App\Features\GroupTalk\GroupTalkRoomNotificationRows;
use App\Features\GroupTalk\TalkReadCursor;
use App\Features\Timeline\Actions\ResolveMentions;
use App\Files\PostImages;
use App\Jobs\SyncLinkCard;
use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\Member;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;

class CreateGroupMessage
{
    public function __construct(
        private readonly PostImages $images,
        private readonly ResolveMentions $mentions,
        private readonly GroupTalkRoomNotificationRows $rows,
    ) {}

    /**
     * $body arrives normalized and bounded by the caller (LF newlines, at most 5,000 code points),
     * and $mentions is the picker's selection, not yet checked against that body. Nothing may wrap
     * `PostImages::attach()` in a further transaction (docs/internals/group-talk.md, "Images").
     *
     * @param  list<array{member_id: int, offset: int, length: int}>  $mentions
     * @param  array<int, UploadedFile>  $images
     *
     * @throws GroupTalkActionException
     */
    public function __invoke(
        Member $author,
        Group $group,
        string $body,
        array $mentions = [],
        array $images = [],
        bool $mentionsRequired = false,
        ?GroupMessage $inReplyTo = null,
    ): GroupMessage {
        if (! GroupTalkAccess::canPost($group, $author)) {
            throw new GroupTalkActionException(GroupTalkActionFailure::CannotPost);
        }

        if ($inReplyTo !== null && (int) $inReplyTo->group_id !== (int) $group->getKey()) {
            throw new GroupTalkActionException(GroupTalkActionFailure::UnknownMessage);
        }

        return $this->images->attach(
            'groupMessage',
            $images,
            persist: function () use ($author, $group, $body, $mentions, $mentionsRequired, $inReplyTo): GroupMessage {
                $message = GroupMessage::create([
                    'group_id' => $group->getKey(),
                    'member_id' => $author->getKey(),
                    'body' => $body,
                    'in_reply_to_id' => $inReplyTo?->getKey(),
                ]);

                // Resolved inside the transaction: resolution share-locks the members it matches, so
                // one deleted mid-request drops its row and the message still posts, instead of
                // failing the FK insert and rolling the message back.
                $resolved = ($this->mentions)($author, $body, $mentions, $group);

                // Thrown from inside the transaction on purpose: the body carrying the handle is
                // rolled back along with the row that was supposed to explain it.
                if ($mentionsRequired && count($resolved) !== count($mentions)) {
                    throw new GroupTalkActionException(GroupTalkActionFailure::MentionDropped);
                }

                // createMany answers in the order it was given, so the relation keeps resolution's
                // ascending offsets.
                $message->setRelation('mentions', $message->mentions()->createMany($resolved));

                // Inside the insert's transaction: a cursor left behind the author's own message
                // would show their own words arriving as unread.
                TalkReadCursor::advance(
                    (int) $group->getKey(),
                    (int) $author->getKey(),
                    CarbonImmutable::instance($message->created_at),
                    (int) $message->getKey(),
                );

                $this->rows->markRead((int) $author->getKey(), (int) $group->getKey());

                // Dispatched from inside the write so the snapshot is the rows just stored; delivery
                // waits for the commit (ShouldDispatchAfterCommit).
                GroupMessagePosted::dispatch($message, $author, ResolveMentions::memberIds($resolved));

                // Likewise held until the commit: the job re-reads the row by id (SyncLinkCard::for).
                SyncLinkCard::for($message);

                return $message;
            },
            relation: fn (GroupMessage $message) => $message->images(),
        );
    }
}
