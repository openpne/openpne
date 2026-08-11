<?php

namespace Tests\Feature\Timeline\Modern;

use App\Models\Member;
use App\Models\MemberImage;
use App\Models\TimelinePost;
use App\Support\AvatarColor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimelineRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['openpne.surface_mode' => 'modern_default']);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $member = Member::factory()->create();
        $post = TimelinePost::factory()->create(['member_id' => $member->getKey()]);

        $this->get("/member/{$member->getKey()}/timeline")->assertRedirect('/login');
        $this->get("/timeline/{$post->getKey()}")->assertRedirect('/login');
    }

    public function test_modern_member_renders_inertia_component(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)
            ->get("/member/{$member->getKey()}/timeline")
            ->assertInertia(fn ($page) => $page->component('timeline/member'));
    }

    public function test_the_member_timeline_owner_ref_carries_the_avatar_the_chrome_scope_draws(): void
    {
        $member = Member::factory()->create();
        $member->forceFill(['avatar_color' => AvatarColor::Green])->save();
        MemberImage::factory()->create(['member_id' => $member->getKey()]);
        $expected = $member->load('avatar.file')->avatar->file->thumbnailUrl(120, 120, square: true);

        $this->actingAs($member)
            ->get("/member/{$member->getKey()}/timeline")
            ->assertInertia(fn ($page) => $page
                ->where('owner.imageUrl', $expected)
                ->where('owner.avatarColor', '#15803d')
            );
    }

    public function test_modern_member_timeline_carries_the_reply_count(): void
    {
        $member = Member::factory()->create();
        $post = TimelinePost::factory()->create(['member_id' => $member->getKey()]);
        TimelinePost::factory()->count(3)->create(['member_id' => $member->getKey(), 'in_reply_to_id' => $post->getKey()]);

        $this->actingAs($member)
            ->get("/member/{$member->getKey()}/timeline")
            ->assertInertia(fn ($page) => $page
                ->has('posts.data', 1) // replies are not separate rows
                ->where('posts.data.0.id', $post->getKey())
                ->where('posts.data.0.replyCount', 3)
            );
    }

    public function test_member_timeline_falls_back_to_classic_with_op3_body_id(): void
    {
        // When timeline is not native, the canonical route falls back to Classic; the body id
        // must still be the OpenPNE 3 hook, not empty.
        config()->set('features.timeline.modern_status', 'fallback');
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->get("/member/{$member->getKey()}/timeline");

        $response->assertOk();
        $response->assertSee('id="page_timeline_member"', false);
    }

    public function test_modern_show_renders_inertia_component_with_post_props(): void
    {
        $member = Member::factory()->create();
        $post = TimelinePost::factory()->create(['member_id' => $member->getKey()]);

        $this->actingAs($member)
            ->get("/timeline/{$post->getKey()}")
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

        $this->actingAs($alice)->get("/timeline/{$post->getKey()}")->assertNotFound();
    }

    public function test_visibility_slug_is_string_in_inertia_props(): void
    {
        $member = Member::factory()->create();
        $post = TimelinePost::factory()->create(['member_id' => $member->getKey()]);

        $this->actingAs($member)
            ->get("/timeline/{$post->getKey()}")
            ->assertInertia(fn ($page) => $page->where('post.visibility', 'members'));
    }
}
