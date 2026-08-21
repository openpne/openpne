<?php

namespace App\Features\GroupTopic\Actions;

use App\Features\GroupTopic\Events\TopicCommentPosted;
use App\Features\GroupTopic\Exceptions\GroupTopicActionException;
use App\Features\GroupTopic\Exceptions\GroupTopicActionFailure;
use App\Features\GroupTopic\GroupTopicAccess;
use App\Files\PostImages;
use App\Jobs\SyncLinkCard;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use App\Models\Member;
use Illuminate\Http\UploadedFile;

class CreateTopicComment
{
    public function __construct(private readonly PostImages $images) {}

    /**
     * Append a comment to a topic the author may comment on. `number` is the per-topic sequence;
     * lock the parent topic row first so concurrent commenters serialize on a row that always
     * exists (an empty thread has no comment rows to lock, so max(number) alone would let two posts
     * both claim 1). The same row update bumps the topic's updated_at, which OpenPNE 3 got for free
     * from its cascade-save: a new comment lifts the topic to the top of the board (ordered by
     * updated_at) and refreshes topic_updated_at for the widget feeds.
     *
     * @param  array<int, UploadedFile>  $images  attached images (slot 1..N), at most the upload cap
     */
    public function __invoke(Member $author, GroupTopic $topic, string $body, array $images = []): GroupTopicComment
    {
        if (! GroupTopicAccess::canComment($topic, $author)) {
            throw new GroupTopicActionException(GroupTopicActionFailure::CannotComment);
        }

        return $this->images->attach(
            'groupTopicComment',
            $images,
            persist: function () use ($author, $topic, $body): GroupTopicComment {
                GroupTopic::whereKey($topic->getKey())->lockForUpdate()->first();

                $number = (int) $topic->comments()->max('number') + 1;

                $comment = $topic->comments()->create([
                    'member_id' => $author->getKey(),
                    'number' => $number,
                    'body' => $body,
                ]);

                $topic->topic_updated_at = now();
                $topic->save(); // dirty → updated_at bumped too, lifting the topic on the board

                TopicCommentPosted::dispatch($topic, $comment, $author);
                // Held until the commit: the job re-reads the row by id (SyncLinkCard::for).
                SyncLinkCard::for($comment);

                return $comment;
            },
            relation: fn (GroupTopicComment $comment) => $comment->images(),
        );
    }
}
