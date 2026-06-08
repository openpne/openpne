<?php

namespace App\Features\CommunityEvent\Actions;

use App\Features\CommunityEvent\CommunityEventAccess;
use App\Features\CommunityEvent\Data\CommunityEventFormData;
use App\Features\CommunityEvent\Exceptions\CommunityEventActionException;
use App\Features\CommunityEvent\Exceptions\CommunityEventActionFailure;
use App\Files\PostImages;
use App\Models\Community;
use App\Models\CommunityEvent;
use App\Models\Member;
use Illuminate\Http\UploadedFile;

class CreateEvent
{
    public function __construct(private readonly PostImages $images) {}

    /**
     * @param  array<int, UploadedFile>  $images  attached images (slot 1..N), at most the upload cap
     */
    public function __invoke(Member $author, Community $community, CommunityEventFormData $data, array $images = []): CommunityEvent
    {
        if (! CommunityEventAccess::canPostEvent($community, $author)) {
            throw new CommunityEventActionException(CommunityEventActionFailure::CannotPost);
        }

        // event_updated_at starts at creation time (OpenPNE 3 sets it whenever name/body change,
        // which a fresh event does).
        return $this->images->attach(
            'communityEvent',
            $images,
            persist: fn (): CommunityEvent => $community->events()->create([
                'member_id' => $author->getKey(),
                'name' => $data->name,
                'body' => $data->body,
                'open_date' => $data->open_date,
                'open_date_comment' => $data->open_date_comment,
                'area' => $data->area,
                'application_deadline' => $data->application_deadline,
                'capacity' => $data->capacity,
                'event_updated_at' => now(),
            ]),
            relation: fn (CommunityEvent $event) => $event->images(),
        );
    }
}
