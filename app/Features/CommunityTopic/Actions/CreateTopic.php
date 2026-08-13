<?php

namespace App\Features\CommunityTopic\Actions;

use App\Features\CommunityTopic\CommunityTopicAccess;
use App\Features\CommunityTopic\Data\CommunityTopicFormData;
use App\Features\CommunityTopic\Events\TopicPosted;
use App\Features\CommunityTopic\Exceptions\CommunityTopicActionException;
use App\Features\CommunityTopic\Exceptions\CommunityTopicActionFailure;
use App\Files\PostImages;
use App\Jobs\SyncLinkCard;
use App\Models\CommunityTopic;
use App\Models\Group;
use App\Models\Member;
use App\Support\BodyFormat;
use Illuminate\Http\UploadedFile;

class CreateTopic
{
    public function __construct(private readonly PostImages $images) {}

    /**
     * @param  array<int, UploadedFile>  $images  attached images (slot 1..N), at most the upload cap
     */
    public function __invoke(Member $author, Group $group, CommunityTopicFormData $data, array $images = []): CommunityTopic
    {
        if (! CommunityTopicAccess::canPostTopic($group, $author)) {
            throw new CommunityTopicActionException(CommunityTopicActionFailure::CannotPost);
        }

        // topic_updated_at starts at creation time (OpenPNE 3 bumps it whenever name/body change,
        // which a fresh topic does); created_at = updated_at keep the board ordering sane.
        $topic = $this->images->attach(
            'communityTopic',
            $images,
            persist: fn (): CommunityTopic => $group->topics()->create([
                'member_id' => $author->getKey(),
                'name' => $data->name,
                'body' => $data->body,
                'topic_updated_at' => now(),
                'format' => $data->format ?? BodyFormat::Plain,
            ]),
            relation: fn (CommunityTopic $topic) => $topic->images(),
        );

        // Fires after the image-attach transaction commits (ShouldDispatchAfterCommit); the fan-out job
        // re-reads a durable topic.
        TopicPosted::dispatch($topic, $author);
        SyncLinkCard::for($topic);

        return $topic;
    }
}
