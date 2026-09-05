<?php

namespace Tests\Feature\Timeline\Modern;

use App\Features\Timeline\Actions\CreateTimelinePost;
use App\Features\Timeline\Data\TimelinePostFormData;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TagRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['openpne.surface_mode' => 'modern_default']);
    }

    public function test_the_feed_carries_the_tag_ranges_in_body_order(): void
    {
        $author = Member::factory()->create();
        $this->createPost($author, '＃ＴＡＧ and #op4');

        $this->actingAs($author)->get('/timeline')
            ->assertInertia(fn ($page) => $page
                ->where('posts.data.0.body', '＃ＴＡＧ and #op4')
                ->where('posts.data.0.tags', [
                    ['tag' => 'tag', 'offset' => 0, 'length' => 4],
                    ['tag' => 'op4', 'offset' => 9, 'length' => 4],
                ])
                ->etc()
            );
    }

    public function test_a_post_with_no_hashtag_carries_an_empty_list(): void
    {
        $author = Member::factory()->create();
        TimelinePost::factory()->create(['member_id' => $author->getKey(), 'visibility' => Visibility::Members]);

        $this->actingAs($author)->get('/timeline')
            ->assertInertia(fn ($page) => $page->where('posts.data.0.tags', [])->etc());
    }

    public function test_the_thread_carries_the_ranges_of_the_root_and_of_every_reply(): void
    {
        $author = Member::factory()->create();
        $post = $this->createPost($author, 'shipped #op4');
        $reply = TimelinePost::factory()->replyTo($post)->create(['member_id' => $author->getKey(), 'body' => 'nice #op4']);
        $reply->tags()->create(['tag' => 'op4', 'offset' => 5, 'length' => 4]);

        $this->actingAs($author)->get("/timeline/{$post->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->where('post.tags', [['tag' => 'op4', 'offset' => 8, 'length' => 4]])
                ->where('replies.0.tags', [['tag' => 'op4', 'offset' => 5, 'length' => 4]])
                ->etc()
            );
    }

    public function test_the_tag_page_renders_its_component_with_the_normalized_tag(): void
    {
        $author = Member::factory()->create();
        $tagged = $this->createPost($author, 'shipped #op4 today');
        $this->createPost($author, 'nothing to see here');

        $this->actingAs($author)->get('/timeline/tag/OP4')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('timeline/tag')
                ->where('tag', 'op4')
                ->has('posts.data', 1)
                ->where('posts.data.0.id', $tagged->getKey())
            );
    }

    public function test_a_tag_nobody_used_renders_an_empty_feed(): void
    {
        $this->actingAs(Member::factory()->create())->get('/timeline/tag/nobody')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('timeline/tag')->has('posts.data', 0));
    }

    private function createPost(Member $author, string $body, Visibility $visibility = Visibility::Members): TimelinePost
    {
        return app(CreateTimelinePost::class)($author, new TimelinePostFormData($body, $visibility));
    }
}
