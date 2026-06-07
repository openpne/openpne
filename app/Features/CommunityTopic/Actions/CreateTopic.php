<?php

namespace App\Features\CommunityTopic\Actions;

use App\Features\CommunityTopic\CommunityTopicAccess;
use App\Features\CommunityTopic\CommunityTopicImages;
use App\Features\CommunityTopic\Data\CommunityTopicFormData;
use App\Features\CommunityTopic\Exceptions\CommunityTopicActionException;
use App\Features\CommunityTopic\Exceptions\CommunityTopicActionFailure;
use App\Models\Community;
use App\Models\CommunityTopic;
use App\Models\Member;
use Illuminate\Http\UploadedFile;

class CreateTopic
{
    public function __construct(private readonly CommunityTopicImages $images) {}

    /**
     * @param  array<int, UploadedFile>  $images  attached images (slot 1..N), at most the upload cap
     */
    public function __invoke(Member $author, Community $community, CommunityTopicFormData $data, array $images = []): CommunityTopic
    {
        if (! CommunityTopicAccess::canPostTopic($community, $author)) {
            throw new CommunityTopicActionException(CommunityTopicActionFailure::CannotPost);
        }

        // topic_updated_at starts at creation time (OpenPNE 3 bumps it whenever name/body change,
        // which a fresh topic does); created_at = updated_at keep the board ordering sane.
        return $this->images->attach(
            'communityTopic',
            $images,
            persist: fn (): CommunityTopic => $community->topics()->create([
                'member_id' => $author->getKey(),
                'name' => $data->name,
                'body' => $data->body,
                'topic_updated_at' => now(),
            ]),
            relation: fn (CommunityTopic $topic) => $topic->images(),
        );
    }
}
