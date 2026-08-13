<?php

namespace App\Features\Timeline\Actions;

use App\Features\Timeline\CommunityTimelineAccess;
use App\Features\Timeline\Data\TimelinePostFormData;
use App\Features\Timeline\Events\TimelinePostPosted;
use App\Features\Timeline\Exceptions\NotGroupMember;
use App\Features\Timeline\HashtagParser;
use App\Files\PostImages;
use App\Jobs\SyncLinkCard;
use App\Models\Group;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Visibility;
use Illuminate\Http\UploadedFile;

class CreateTimelinePost
{
    public function __construct(
        private readonly PostImages $images,
        private readonly ResolveMentions $mentions,
    ) {}

    /**
     * Post to the author's own timeline, or to $group's. OpenPNE 3 allows one image per post;
     * $image is attached as slot 1, with its bytes rolled back if the transaction fails.
     *
     * A community post takes the Group itself rather than an id, and the membership check and
     * the fixed visibility live here rather than in a controller — the write is where they cannot
     * be routed around. Everything a community post's audience needs is the community, so the
     * per-post ladder does not apply and the caller's choice is ignored.
     *
     * @throws NotGroupMember
     */
    public function __invoke(Member $author, TimelinePostFormData $data, ?UploadedFile $image = null, ?Group $group = null): TimelinePost
    {
        if ($group !== null && ! CommunityTimelineAccess::canPost($group, $author)) {
            throw new NotGroupMember;
        }

        $visibility = $group !== null ? Visibility::Members : $data->visibility;

        $post = $this->images->attach(
            'timelinePost',
            $image !== null ? [$image] : [],
            // Mentions resolve inside the transaction: resolution share-locks the mentioned
            // members, so one deleted mid-request fails resolution (row dropped, post goes
            // through) instead of failing the FK insert (post rolled back).
            persist: function () use ($author, $data, $group, $visibility): TimelinePost {
                $post = TimelinePost::create([
                    'member_id' => $author->getKey(),
                    'community_id' => $group?->getKey(),
                    'body' => $data->body,
                    'visibility' => $visibility,
                ]);
                $mentions = ($this->mentions)($author, $data->body, $data->mentions, $group);
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

        // Replies are deliberately not synced: they share this table but are rendered as a thread
        // under the post, where a stack of cards would read as noise.
        SyncLinkCard::for($post);

        return $post;
    }
}
