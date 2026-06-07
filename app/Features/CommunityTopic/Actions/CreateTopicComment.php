<?php

namespace App\Features\CommunityTopic\Actions;

use App\Features\CommunityTopic\CommunityTopicAccess;
use App\Features\CommunityTopic\CommunityTopicImages;
use App\Features\CommunityTopic\Exceptions\CommunityTopicActionException;
use App\Features\CommunityTopic\Exceptions\CommunityTopicActionFailure;
use App\Models\CommunityTopic;
use App\Models\CommunityTopicComment;
use App\Models\Member;
use Illuminate\Http\UploadedFile;

class CreateTopicComment
{
    public function __construct(private readonly CommunityTopicImages $images) {}

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
    public function __invoke(Member $author, CommunityTopic $topic, string $body, array $images = []): CommunityTopicComment
    {
        if (! CommunityTopicAccess::canComment($topic, $author)) {
            throw new CommunityTopicActionException(CommunityTopicActionFailure::CannotComment);
        }

        return $this->images->attach(
            'communityTopicComment',
            $images,
            persist: function () use ($author, $topic, $body): CommunityTopicComment {
                CommunityTopic::whereKey($topic->getKey())->lockForUpdate()->first();

                $number = (int) $topic->comments()->max('number') + 1;

                $comment = $topic->comments()->create([
                    'member_id' => $author->getKey(),
                    'number' => $number,
                    'body' => $body,
                ]);

                $topic->topic_updated_at = now();
                $topic->save(); // dirty → updated_at bumped too, lifting the topic on the board

                return $comment;
            },
            relation: fn (CommunityTopicComment $comment) => $comment->images(),
        );
    }
}
