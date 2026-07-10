<?php

namespace Tests\Feature\Timeline\Modern;

use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimelineHomeFeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['openpne.surface_mode' => 'modern_default']);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/timeline')->assertRedirect('/login');
    }

    public function test_modern_home_feed_renders_inertia_component_with_viewer_and_posts(): void
    {
        $member = Member::factory()->create();
        TimelinePost::factory()->create(['member_id' => $member->getKey(), 'visibility' => Visibility::Members]);

        $this->actingAs($member)
            ->get('/timeline')
            ->assertInertia(fn ($page) => $page
                ->component('timeline/index')
                ->where('viewerId', $member->getKey())
                ->has('posts.data', 1)
                ->has('posts.meta')
            );
    }

    public function test_modern_home_feed_carries_the_reply_count_on_top_level_posts(): void
    {
        $member = Member::factory()->create();
        $post = TimelinePost::factory()->create(['member_id' => $member->getKey(), 'visibility' => Visibility::Members]);
        TimelinePost::factory()->count(2)->create([
            'member_id' => $member->getKey(),
            'in_reply_to_id' => $post->getKey(),
            'visibility' => Visibility::Members,
        ]);

        $this->actingAs($member)
            ->get('/timeline')
            ->assertInertia(fn ($page) => $page
                ->has('posts.data', 1) // replies are not separate rows
                ->where('posts.data.0.id', $post->getKey())
                ->where('posts.data.0.replyCount', 2)
            );
    }

    public function test_home_feed_falls_back_to_classic_with_op3_body_id(): void
    {
        config()->set('features.timeline.modern_status', 'fallback');
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->get('/timeline');

        $response->assertOk();
        $response->assertSee('id="page_timeline_sns"', false);
    }
}
