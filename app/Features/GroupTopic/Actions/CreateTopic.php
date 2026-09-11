<?php

namespace App\Features\GroupTopic\Actions;

use App\Features\GroupTopic\Data\GroupTopicFormData;
use App\Features\GroupTopic\Events\TopicPosted;
use App\Features\GroupTopic\Exceptions\GroupTopicActionException;
use App\Features\GroupTopic\Exceptions\GroupTopicActionFailure;
use App\Features\GroupTopic\GroupTopicAccess;
use App\Files\PostImages;
use App\Jobs\SyncLinkCard;
use App\Models\Group;
use App\Models\GroupTopic;
use App\Models\Member;
use App\Support\BodyFormat;
use Illuminate\Http\UploadedFile;

class CreateTopic
{
    public function __construct(private readonly PostImages $images) {}

    /**
     * @param  array<int, UploadedFile>  $images  attached images (slot 1..N), at most the upload cap
     */
    public function __invoke(Member $author, Group $group, GroupTopicFormData $data, array $images = []): GroupTopic
    {
        if (! GroupTopicAccess::canPostTopic($group, $author)) {
            throw new GroupTopicActionException(GroupTopicActionFailure::CannotPost);
        }

        $topic = $this->images->attach(
            'groupTopic',
            $images,
            // One instant for created_at and bumped_at: created_at is not fillable, so it is forced.
            persist: fn (): GroupTopic => tap($group->topics()->make([
                'member_id' => $author->getKey(),
                'name' => $data->name,
                'body' => $data->body,
                'format' => $data->format ?? BodyFormat::Plain,
            ])->forceFill(['created_at' => $now = now(), 'bumped_at' => $now]))->save(),
            relation: fn (GroupTopic $topic) => $topic->images(),
        );

        TopicPosted::dispatch($topic, $author);
        SyncLinkCard::for($topic);

        return $topic;
    }
}
