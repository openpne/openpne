<?php

namespace App\Features\GroupEvent\Actions;

use App\Features\GroupEvent\Data\GroupEventFormData;
use App\Features\GroupEvent\Events\EventPosted;
use App\Features\GroupEvent\Exceptions\GroupEventActionException;
use App\Features\GroupEvent\Exceptions\GroupEventActionFailure;
use App\Features\GroupEvent\GroupEventAccess;
use App\Files\PostImages;
use App\Jobs\SyncLinkCard;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\Member;
use App\Support\BodyFormat;
use Illuminate\Http\UploadedFile;

class CreateEvent
{
    public function __construct(private readonly PostImages $images) {}

    /**
     * @param  array<int, UploadedFile>  $images  attached images (slot 1..N), at most the upload cap
     */
    public function __invoke(Member $author, Group $group, GroupEventFormData $data, array $images = []): GroupEvent
    {
        if (! GroupEventAccess::canPostEvent($group, $author)) {
            throw new GroupEventActionException(GroupEventActionFailure::CannotPost);
        }

        // event_updated_at starts at creation time (OpenPNE 3 sets it whenever name/body change,
        // which a fresh event does).
        $event = $this->images->attach(
            'groupEvent',
            $images,
            persist: fn (): GroupEvent => $group->events()->create([
                'member_id' => $author->getKey(),
                'name' => $data->name,
                'body' => $data->body,
                'open_date' => $data->open_date,
                'open_date_comment' => $data->open_date_comment,
                'area' => $data->area,
                'application_deadline' => $data->application_deadline,
                'capacity' => $data->capacity,
                'event_updated_at' => now(),
                'format' => $data->format ?? BodyFormat::Plain,
            ]),
            relation: fn (GroupEvent $event) => $event->images(),
        );

        EventPosted::dispatch($event, $author);
        SyncLinkCard::for($event);

        return $event;
    }
}
