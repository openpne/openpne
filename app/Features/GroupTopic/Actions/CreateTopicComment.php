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
     * Lock the parent topic row first so concurrent commenters serialize on a row that always
     * exists: an empty thread has no comment rows, so max(number) alone would let two posts both
     * claim 1. The same save bumps updated_at, lifting the topic as OpenPNE 3's cascade-save did.
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
                $topic->save();

                TopicCommentPosted::dispatch($topic, $comment, $author);
                // Held until the commit: the job re-reads the row by id (SyncLinkCard::for).
                SyncLinkCard::for($comment);

                return $comment;
            },
            relation: fn (GroupTopicComment $comment) => $comment->images(),
        );
    }
}
