<?php

namespace App\Features\Timeline\Actions;

use App\Features\Timeline\Data\TimelinePostFormData;
use App\Files\PostImages;
use App\Models\Member;
use App\Models\TimelinePost;
use Illuminate\Http\UploadedFile;

class CreateTimelinePost
{
    public function __construct(private readonly PostImages $images) {}

    /**
     * Post to the author's own timeline. OpenPNE 3 allows one image per post; $image is attached as
     * slot 1, with its bytes rolled back if the transaction fails.
     */
    public function __invoke(Member $author, TimelinePostFormData $data, ?UploadedFile $image = null): TimelinePost
    {
        return $this->images->attach(
            'timelinePost',
            $image !== null ? [$image] : [],
            persist: fn (): TimelinePost => TimelinePost::create([
                'member_id' => $author->getKey(),
                'body' => $data->body,
                'visibility' => $data->visibility,
            ]),
            relation: fn (TimelinePost $post) => $post->images(),
        );
    }
}
