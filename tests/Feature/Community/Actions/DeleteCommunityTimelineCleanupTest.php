<?php

namespace Tests\Feature\Community\Actions;

use App\Features\Community\Actions\DeleteCommunity;
use App\Features\Timeline\Actions\CreateTimelinePost;
use App\Features\Timeline\Data\TimelinePostFormData;
use App\Models\Community;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Models\TimelinePostImage;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Deleting a community must not leave its timeline's image bytes behind. The community_id cascade
 * removes the posts and their timeline_post_images join rows, but a File row (and the bytes a disk
 * backend holds) survives a cascade — the same reason DeleteCommunity already purges topics and
 * events through their own actions.
 */
class DeleteCommunityTimelineCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_purges_the_image_files_of_the_communitys_timeline_posts(): void
    {
        $community = Community::factory()->create();
        $author = Member::factory()->create();

        $post = app(CreateTimelinePost::class)(
            $author,
            new TimelinePostFormData('pic', Visibility::Members),
            UploadedFile::fake()->image('p.png', 20, 20),
        );
        $post->update(['community_id' => $community->getKey()]);
        $fileId = $post->fresh()->images->first()->file_id;

        app(DeleteCommunity::class)->purge($community);

        $this->assertDatabaseMissing('timeline_posts', ['id' => $post->getKey()]);
        $this->assertDatabaseMissing('timeline_post_images', ['timeline_post_id' => $post->getKey()]);
        $this->assertDatabaseMissing('files', ['id' => $fileId]);
    }

    public function test_leaves_sns_wide_posts_alone(): void
    {
        $community = Community::factory()->create();
        $snsPost = TimelinePost::factory()->create();

        app(DeleteCommunity::class)->purge($community);

        $this->assertDatabaseHas('timeline_posts', ['id' => $snsPost->getKey()]);
    }

    public function test_replies_and_the_images_they_carry_go_with_their_parent(): void
    {
        $community = Community::factory()->create();
        $parent = TimelinePost::factory()->inCommunity($community)->create();
        $reply = TimelinePost::factory()->replyTo($parent)->create();
        $image = TimelinePostImage::factory()->create(['timeline_post_id' => $reply->getKey()]);

        app(DeleteCommunity::class)->purge($community);

        $this->assertDatabaseMissing('timeline_posts', ['id' => $reply->getKey()]);
        $this->assertDatabaseMissing('files', ['id' => $image->file_id]);
    }
}
