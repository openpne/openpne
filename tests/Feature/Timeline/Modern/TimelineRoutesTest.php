<?php

namespace Tests\Feature\Timeline\Modern;

use App\Models\Member;
use App\Models\TimelinePost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimelineRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login_for_modern_routes(): void
    {
        $member = Member::factory()->create();
        $post = TimelinePost::factory()->create(['member_id' => $member->getKey()]);

        $this->get("/m/member/{$member->getKey()}/timeline")->assertRedirect('/login');
        $this->get("/m/timeline/{$post->getKey()}")->assertRedirect('/login');
    }

    public function test_modern_member_renders_inertia_component(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)
            ->get("/m/member/{$member->getKey()}/timeline")
            ->assertInertia(fn ($page) => $page->component('timeline/member'));
    }

    public function test_modern_member_falls_back_to_classic_with_op3_body_id(): void
    {
        // When timeline is not native, a /m/* route falls back to Classic; the body id must
        // still be the OpenPNE 3 hook derived from the canonical route, not empty.
        config()->set('features.timeline.modern_status', 'fallback');
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->get("/m/member/{$member->getKey()}/timeline");

        $response->assertOk();
        $response->assertSee('id="page_timeline_member"', false);
    }

    public function test_modern_show_renders_inertia_component_with_post_props(): void
    {
        $member = Member::factory()->create();
        $post = TimelinePost::factory()->create(['member_id' => $member->getKey()]);

        $this->actingAs($member)
            ->get("/m/timeline/{$post->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->component('timeline/show')
                ->has('post.id')
                ->has('post.body')
                ->has('post.visibility')
                ->where('post.id', $post->getKey())
            );
    }

    public function test_modern_show_returns_404_for_non_viewable_post(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create()->all();
        $post = TimelinePost::factory()->private()->create(['member_id' => $bob->getKey()]);

        $this->actingAs($alice)->get("/m/timeline/{$post->getKey()}")->assertNotFound();
    }

    public function test_visibility_slug_is_string_in_inertia_props(): void
    {
        $member = Member::factory()->create();
        $post = TimelinePost::factory()->create(['member_id' => $member->getKey()]);

        $this->actingAs($member)
            ->get("/m/timeline/{$post->getKey()}")
            ->assertInertia(fn ($page) => $page->where('post.visibility', 'members'));
    }
}
