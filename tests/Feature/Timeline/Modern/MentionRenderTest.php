<?php

namespace Tests\Feature\Timeline\Modern;

use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MentionRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['openpne.surface_mode' => 'modern_default']);
    }

    public function test_the_feed_carries_the_mention_ranges_in_body_order(): void
    {
        $author = Member::factory()->create();
        $alice = Member::factory()->create(['name' => 'Alice']);
        $bob = Member::factory()->create(['name' => 'Bob']);
        $post = TimelinePost::factory()->create([
            'member_id' => $author->getKey(),
            'visibility' => Visibility::Members,
            'body' => 'hi @Alice and @Bob',
        ]);
        $post->mentions()->createMany([
            ['member_id' => $bob->getKey(), 'offset' => 14, 'length' => 4],
            ['member_id' => $alice->getKey(), 'offset' => 3, 'length' => 6],
        ]);

        $this->actingAs($author)->get('/timeline')
            ->assertInertia(fn ($page) => $page
                ->where('posts.data.0.body', 'hi @Alice and @Bob')
                ->where('posts.data.0.mentions', [
                    ['memberId' => $alice->getKey(), 'offset' => 3, 'length' => 6],
                    ['memberId' => $bob->getKey(), 'offset' => 14, 'length' => 4],
                ])
                ->etc()
            );
    }

    public function test_a_post_with_no_mentions_carries_an_empty_list(): void
    {
        $author = Member::factory()->create();
        TimelinePost::factory()->create(['member_id' => $author->getKey(), 'visibility' => Visibility::Members]);

        $this->actingAs($author)->get('/timeline')
            ->assertInertia(fn ($page) => $page->where('posts.data.0.mentions', [])->etc());
    }

    public function test_the_thread_carries_the_ranges_of_the_root_and_of_every_reply(): void
    {
        $author = Member::factory()->create();
        $alice = Member::factory()->create(['name' => 'Alice']);
        $post = TimelinePost::factory()->create([
            'member_id' => $author->getKey(),
            'visibility' => Visibility::Members,
            'body' => 'hi @Alice',
        ]);
        $post->mentions()->create(['member_id' => $alice->getKey(), 'offset' => 3, 'length' => 6]);
        $reply = TimelinePost::factory()->replyTo($post)->create(['member_id' => $author->getKey(), 'body' => 'and @Alice again']);
        $reply->mentions()->create(['member_id' => $alice->getKey(), 'offset' => 4, 'length' => 6]);

        $this->actingAs($author)->get("/timeline/{$post->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->where('post.mentions', [['memberId' => $alice->getKey(), 'offset' => 3, 'length' => 6]])
                ->where('replies.0.mentions', [['memberId' => $alice->getKey(), 'offset' => 4, 'length' => 6]])
                ->etc()
            );
    }
}
