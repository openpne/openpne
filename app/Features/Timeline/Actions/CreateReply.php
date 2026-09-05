<?php

namespace App\Features\Timeline\Actions;

use App\Features\Timeline\Events\TimelineReplyPosted;
use App\Features\Timeline\HashtagParser;
use App\Jobs\SyncLinkCard;
use App\Models\Member;
use App\Models\TimelinePost;
use Illuminate\Support\Facades\DB;

class CreateReply
{
    public function __construct(private readonly ResolveMentions $mentions) {}

    /**
     * The caller gates viewability and re-centers to the root, so `$parent` is always top-level. A
     * reply carries no image (OpenPNE 3 parity) and inherits the parent's visibility, so a thread is
     * one audience (docs/internals/timeline.md, "Key invariants").
     *
     * @param  list<array{member_id: int, offset: int, length: int}>  $mentions  the picker's selection, not yet resolved against $body
     */
    public function __invoke(Member $author, TimelinePost $parent, string $body, array $mentions = []): TimelinePost
    {
        // Mentions resolve inside the transaction: resolution share-locks the mentioned members,
        // so one deleted mid-request fails resolution (row dropped, reply goes through) instead
        // of failing the FK insert (reply rolled back).
        return DB::transaction(function () use ($author, $parent, $body, $mentions): TimelinePost {
            $reply = TimelinePost::create([
                'member_id' => $author->getKey(),
                'in_reply_to_id' => $parent->getKey(),
                'body' => $body,
                'visibility' => $parent->visibility,
            ]);
            $resolved = ($this->mentions)($author, $body, $mentions);
            $reply->mentions()->createMany($resolved);
            // After resolution, because a mention wins any range the two would both claim.
            $reply->tags()->createMany(HashtagParser::parse($body, $resolved));

            // Dispatched here so the snapshot is taken from the rows just written; delivery waits
            // for the commit (ShouldDispatchAfterCommit).
            TimelineReplyPosted::dispatch($reply, $author, ResolveMentions::memberIds($resolved));

            // Likewise held until the commit: the job re-reads the row by id (SyncLinkCard::for).
            SyncLinkCard::for($reply);

            return $reply;
        });
    }
}
