<?php

namespace Tests\Feature\Timeline\Actions;

use App\Features\Timeline\Actions\CreateTimelinePost;
use App\Features\Timeline\Data\TimelinePostFormData;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CreateTimelinePostTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_top_level_post_owned_by_the_author(): void
    {
        $author = Member::factory()->create();

        $post = app(CreateTimelinePost::class)($author, new TimelinePostFormData('Hello timeline', Visibility::Friends));

        $this->assertDatabaseHas('timeline_posts', [
            'id' => $post->getKey(),
            'member_id' => $author->getKey(),
            'body' => 'Hello timeline',
            'visibility' => Visibility::Friends->value,
            'in_reply_to_id' => null,
        ]);
        $this->assertSame(Visibility::Friends, $post->visibility);
    }

    public function test_attaches_a_single_owned_image_as_slot_1(): void
    {
        $author = Member::factory()->create();
        $image = UploadedFile::fake()->image('p.png', 20, 20);

        $post = app(CreateTimelinePost::class)($author, new TimelinePostFormData('pic', Visibility::Members), $image);

        $images = $post->fresh()->images;
        $this->assertCount(1, $images);
        $this->assertSame(1, $images->first()->number);
        $this->assertDatabaseHas('files', [
            'id' => $images->first()->file_id,
            'related_entity_type' => 'timelinePost',
            'related_entity_id' => $post->getKey(),
        ]);
    }
}
