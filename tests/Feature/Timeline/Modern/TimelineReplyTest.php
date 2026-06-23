<?php

namespace Tests\Feature\Timeline\Modern;

use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimelineReplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_modern_show_includes_replies_and_viewer_id(): void
    {
        $author = Member::factory()->create();
        $post = TimelinePost::factory()->create(['member_id' => $author->getKey(), 'visibility' => Visibility::Members]);
        TimelinePost::factory()->replyTo($post)->create(['member_id' => $author->getKey(), 'body' => 'modern reply']);

        $this->actingAs($author)
            ->get("/m/timeline/{$post->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->component('timeline/show')
                ->where('viewerId', $author->getKey())
                ->has('replies', 1)
                ->where('replies.0.body', 'modern reply')
            );
    }

    public function test_modern_reply_permalink_re_centers_to_the_root(): void
    {
        $author = Member::factory()->create();
        $post = TimelinePost::factory()->create(['member_id' => $author->getKey(), 'visibility' => Visibility::Members]);
        $reply = TimelinePost::factory()->replyTo($post)->create(['member_id' => $author->getKey()]);

        $this->actingAs($author)->get("/m/timeline/{$reply->getKey()}")->assertRedirect("/m/timeline/{$post->getKey()}");
    }

    public function test_modern_reply_can_be_posted(): void
    {
        [$author, $viewer] = Member::factory()->count(2)->create()->all();
        $post = TimelinePost::factory()->create(['member_id' => $author->getKey(), 'visibility' => Visibility::Members]);

        $this->actingAs($viewer)
            ->post("/m/timeline/{$post->getKey()}/reply", ['body' => 'modern reply'])
            ->assertRedirect("/m/timeline/{$post->getKey()}");

        $this->assertDatabaseHas('timeline_posts', [
            'in_reply_to_id' => $post->getKey(),
            'body' => 'modern reply',
        ]);
    }
}
