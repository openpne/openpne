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

        $event = $this->images->attach(
            'groupEvent',
            $images,
            // One instant for created_at and bumped_at: created_at is not fillable, so it is forced.
            persist: fn (): GroupEvent => tap($group->events()->make([
                'member_id' => $author->getKey(),
                'name' => $data->name,
                'body' => $data->body,
                'open_date' => $data->open_date,
                'open_date_comment' => $data->open_date_comment,
                'area' => $data->area,
                'application_deadline' => $data->application_deadline,
                'capacity' => $data->capacity,
                'format' => $data->format ?? BodyFormat::Plain,
            ])->forceFill(['created_at' => $now = now(), 'bumped_at' => $now]))->save(),
            relation: fn (GroupEvent $event) => $event->images(),
        );

        EventPosted::dispatch($event, $author);
        SyncLinkCard::for($event);

        return $event;
    }
}
