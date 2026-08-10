<?php

namespace App\Features\Timeline\Actions;

use App\Features\Timeline\Data\TimelinePostFormData;
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
     * Post to the author's own timeline. OpenPNE 3 allows one image per post; $image is attached as
     * slot 1, with its bytes rolled back if the transaction fails.
     */
    public function __invoke(Member $author, TimelinePostFormData $data, ?UploadedFile $image = null): TimelinePost
    {
        // Resolved outside the transaction below: it only reads.
        $mentions = ($this->mentions)($author, $data->body, $data->mentions);

        $post = $this->images->attach(
            'timelinePost',
            $image !== null ? [$image] : [],
            persist: function () use ($author, $data, $mentions): TimelinePost {
                $post = TimelinePost::create([
                    'member_id' => $author->getKey(),
                    'body' => $data->body,
                    'visibility' => $data->visibility,
                ]);
                $post->mentions()->createMany($mentions);

                return $post;
            },
            relation: fn (TimelinePost $post) => $post->images(),
        );

        // Replies are deliberately not synced: they share this table but are rendered as a thread
        // under the post, where a stack of cards would read as noise.
        SyncLinkCard::for($post);

        return $post;
    }
}
