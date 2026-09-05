<?php

namespace App\Features\Timeline\Actions;

use App\Features\Timeline\Data\TimelinePostFormData;
use App\Features\Timeline\Events\TimelinePostPosted;
use App\Features\Timeline\HashtagParser;
use App\Files\PostImages;
use App\Jobs\SyncLinkCard;
use App\Models\Member;
use App\Models\TimelinePost;
use Illuminate\Http\UploadedFile;

class CreateTimelinePost
{
    public function __construct(
        private readonly PostImages $images,
        private readonly ResolveMentions $mentions,
    ) {}

    /**
     * OpenPNE 3 allows one image per post; $image is attached as slot 1, with its bytes rolled back
     * if the transaction fails.
     */
    public function __invoke(Member $author, TimelinePostFormData $data, ?UploadedFile $image = null): TimelinePost
    {
        $post = $this->images->attach(
            'timelinePost',
            $image !== null ? [$image] : [],
            // Mentions resolve inside the transaction: resolution share-locks the mentioned
            // members, so one deleted mid-request fails resolution (row dropped, post goes
            // through) instead of failing the FK insert (post rolled back).
            persist: function () use ($author, $data): TimelinePost {
                $post = TimelinePost::create([
                    'member_id' => $author->getKey(),
                    'body' => $data->body,
                    'visibility' => $data->visibility,
                ]);
                $mentions = ($this->mentions)($author, $data->body, $data->mentions);
                $post->mentions()->createMany($mentions);
                // After resolution, because a mention wins any range the two would both claim.
                $post->tags()->createMany(HashtagParser::parse($data->body, $mentions));

                // Dispatched here so the snapshot is taken from the rows just written; delivery
                // waits for the commit (ShouldDispatchAfterCommit).
                TimelinePostPosted::dispatch($post, $author, ResolveMentions::memberIds($mentions));

                return $post;
            },
            relation: fn (TimelinePost $post) => $post->images(),
        );

        SyncLinkCard::for($post);

        return $post;
    }
}
