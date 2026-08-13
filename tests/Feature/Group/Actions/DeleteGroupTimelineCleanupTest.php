<?php

namespace Tests\Feature\Group\Actions;

use App\Features\Group\Actions\DeleteGroup;
use App\Features\Timeline\Actions\CreateTimelinePost;
use App\Features\Timeline\Data\TimelinePostFormData;
use App\Models\Group;
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
 * backend holds) survives a cascade — the same reason DeleteGroup already purges topics and
 * events through their own actions.
 */
class DeleteGroupTimelineCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_purges_the_image_files_of_the_communitys_timeline_posts(): void
    {
        $group = Group::factory()->create();
        $author = Member::factory()->create();

        $post = app(CreateTimelinePost::class)(
            $author,
            new TimelinePostFormData('pic', Visibility::Members),
            UploadedFile::fake()->image('p.png', 20, 20),
        );
        $post->update(['community_id' => $group->getKey()]);
        $fileId = $post->fresh()->images->first()->file_id;

        app(DeleteGroup::class)->purge($group);

        $this->assertDatabaseMissing('timeline_posts', ['id' => $post->getKey()]);
        $this->assertDatabaseMissing('timeline_post_images', ['timeline_post_id' => $post->getKey()]);
        $this->assertDatabaseMissing('files', ['id' => $fileId]);
    }

    public function test_leaves_sns_wide_posts_alone(): void
    {
        $group = Group::factory()->create();
        $snsPost = TimelinePost::factory()->create();

        app(DeleteGroup::class)->purge($group);

        $this->assertDatabaseHas('timeline_posts', ['id' => $snsPost->getKey()]);
    }

    public function test_replies_and_the_images_they_carry_go_with_their_parent(): void
    {
        $group = Group::factory()->create();
        $parent = TimelinePost::factory()->inGroup($group)->create();
        $reply = TimelinePost::factory()->replyTo($parent)->create();
        $image = TimelinePostImage::factory()->create(['timeline_post_id' => $reply->getKey()]);

        app(DeleteGroup::class)->purge($group);

        $this->assertDatabaseMissing('timeline_posts', ['id' => $reply->getKey()]);
        $this->assertDatabaseMissing('files', ['id' => $image->file_id]);
    }
}
