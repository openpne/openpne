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

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/m/timeline')->assertRedirect('/login');
    }

    public function test_modern_home_feed_renders_inertia_component_with_viewer_and_posts(): void
    {
        $member = Member::factory()->create();
        TimelinePost::factory()->create(['member_id' => $member->getKey(), 'visibility' => Visibility::Members]);

        $this->actingAs($member)
            ->get('/m/timeline')
            ->assertInertia(fn ($page) => $page
                ->component('timeline/index')
                ->where('viewerId', $member->getKey())
                ->has('posts.data', 1)
                ->has('posts.meta')
            );
    }

    public function test_modern_home_feed_falls_back_to_classic_with_op3_body_id(): void
    {
        config()->set('features.timeline.modern_status', 'fallback');
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->get('/m/timeline');

        $response->assertOk();
        $response->assertSee('id="page_timeline_sns"', false);
    }
}
