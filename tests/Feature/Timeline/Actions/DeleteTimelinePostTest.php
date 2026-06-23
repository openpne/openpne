<?php

namespace Tests\Feature\Timeline\Actions;

use App\Features\Timeline\Actions\CreateTimelinePost;
use App\Features\Timeline\Actions\DeleteTimelinePost;
use App\Features\Timeline\Data\TimelinePostFormData;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class DeleteTimelinePostTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletes_the_post(): void
    {
        $post = TimelinePost::factory()->create();

        (new DeleteTimelinePost)($post);

        $this->assertDatabaseMissing('timeline_posts', ['id' => $post->getKey()]);
    }

    public function test_deleting_a_post_cascades_its_replies(): void
    {
        $post = TimelinePost::factory()->create();
        $reply = TimelinePost::factory()->replyTo($post)->create();

        (new DeleteTimelinePost)($post);

        $this->assertDatabaseMissing('timeline_posts', ['id' => $reply->getKey()]);
    }

    public function test_purges_the_owned_image_file_and_join_row(): void
    {
        $author = Member::factory()->create();
        $image = UploadedFile::fake()->image('p.png', 20, 20);
        $post = app(CreateTimelinePost::class)($author, new TimelinePostFormData('pic', Visibility::Members), $image);
        $fileId = $post->fresh()->images->first()->file_id;

        (new DeleteTimelinePost)($post);

        // The FK cascade drops the join row; DeleteTimelinePost purges the File (and its bytes).
        $this->assertDatabaseMissing('timeline_post_images', ['timeline_post_id' => $post->getKey()]);
        $this->assertDatabaseMissing('files', ['id' => $fileId]);
    }
}
