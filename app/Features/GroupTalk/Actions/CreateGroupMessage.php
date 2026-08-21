<?php

namespace App\Features\GroupTalk\Actions;

use App\Features\GroupTalk\Events\GroupMessagePosted;
use App\Features\GroupTalk\Exceptions\GroupTalkActionException;
use App\Features\GroupTalk\Exceptions\GroupTalkActionFailure;
use App\Features\GroupTalk\GroupTalkAccess;
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
    /**
     * ResolveMentions is the timeline's, deliberately: it already narrows mentionability to a
     * group's members when handed one, and duplicating its offset/overlap invariants is how the two
     * would drift apart. The mention machinery is shared between the two surfaces by design.
     */
    public function __construct(
        private readonly PostImages $images,
        private readonly ResolveMentions $mentions,
    ) {}

    /**
     * Say something in a group's talk. Membership is checked here rather than in the controller —
     * the write is the one place it cannot be routed around.
     *
     * $body arrives normalized and bounded by the form request (LF newlines, at most 5,000 code
     * points), and $mentions is the picker's raw selection, not yet checked against that body.
     *
     * $inReplyTo is the message this one answers, and resolving it is the caller's job — it is the
     * caller that knows which group the request named. The same-group half of that is re-asserted
     * here because this is the single write chokepoint, and a reference out of the room would render
     * as a deleted parent forever. There is no foreign key behind the column: a reference to a
     * message that has since been deleted is a state the screen draws, so nothing may erase it.
     *
     * PostImages::attach owns the transaction and everything else runs inside its persist callback.
     * It must be the OUTERMOST layer: its compensation deletes the bytes it stored when the
     * transaction throws, and a transaction wrapped around it would already have rolled back —
     * committing the rollback while the bytes stayed on disk. Slots are numbered in the order the
     * member picked them (1..N), which is the order they are read back in.
     *
     * $mentionsRequired is for a composer that wrote the handles into $body itself (the MCP reply
     * tool): dropping one of its rows would leave a handle naming nobody in a message that cannot be
     * edited, so the whole write rolls back instead and the caller re-composes from what it re-reads.
     * The picker's path leaves it off — there the handle is the member's own text, and losing the
     * message over a decoration is the wrong trade.
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
                // one deleted mid-request fails resolution — the row is dropped and the message still
                // posts — instead of failing the FK insert and rolling the message back.
                //
                // Held as the relation so the serializer that answers this write does not re-read
                // them; createMany returns them in the order it was given, which resolution left
                // ascending.
                $resolved = ($this->mentions)($author, $body, $mentions, $group);

                // Thrown from inside the transaction on purpose: the body carrying the handle is
                // rolled back along with the row that was supposed to explain it.
                if ($mentionsRequired && count($resolved) !== count($mentions)) {
                    throw new GroupTalkActionException(GroupTalkActionFailure::MentionDropped);
                }

                $message->setRelation('mentions', $message->mentions()->createMany($resolved));

                // Writing is reading. In the same transaction as the insert, so the cursor can never
                // be left behind a message the member wrote themselves — which would show as their
                // own words arriving as unread. Still forward-only, so it is safe to run
                // unconditionally.
                TalkReadCursor::advance(
                    (int) $group->getKey(),
                    (int) $author->getKey(),
                    CarbonImmutable::instance($message->created_at),
                    (int) $message->getKey(),
                );

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
