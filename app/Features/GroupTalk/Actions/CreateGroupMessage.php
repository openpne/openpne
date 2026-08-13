<?php

namespace App\Features\GroupTalk\Actions;

use App\Features\GroupTalk\Events\GroupMessagePosted;
use App\Features\GroupTalk\Exceptions\GroupTalkActionException;
use App\Features\GroupTalk\Exceptions\GroupTalkActionFailure;
use App\Features\GroupTalk\GroupTalkAccess;
use App\Features\GroupTalk\TalkReadCursor;
use App\Features\Timeline\Actions\ResolveMentions;
use App\Files\PostImages;
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
     * in_reply_to_id is never written: it exists to receive what migrated content pointed at, and
     * talk has no reply UI to produce one.
     *
     * PostImages::attach owns the transaction and everything else runs inside its persist callback.
     * It must be the OUTERMOST layer: its compensation deletes the bytes it stored when the
     * transaction throws, and a transaction wrapped around it would already have rolled back —
     * committing the rollback while the bytes stayed on disk. Slots are numbered in the order the
     * member picked them (1..N), which is the order they are read back in.
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
    ): GroupMessage {
        if (! GroupTalkAccess::canPost($group, $author)) {
            throw new GroupTalkActionException(GroupTalkActionFailure::CannotPost);
        }

        return $this->images->attach(
            'groupMessage',
            $images,
            persist: function () use ($author, $group, $body, $mentions): GroupMessage {
                $message = GroupMessage::create([
                    'group_id' => $group->getKey(),
                    'member_id' => $author->getKey(),
                    'body' => $body,
                ]);

                // Resolved inside the transaction: resolution share-locks the members it matches, so
                // one deleted mid-request fails resolution — the row is dropped and the message still
                // posts — instead of failing the FK insert and rolling the message back.
                //
                // Held as the relation so the serializer that answers this write does not re-read
                // them; createMany returns them in the order it was given, which resolution left
                // ascending.
                $resolved = ($this->mentions)($author, $body, $mentions, $group);
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

                return $message;
            },
            relation: fn (GroupMessage $message) => $message->images(),
        );
    }
}
