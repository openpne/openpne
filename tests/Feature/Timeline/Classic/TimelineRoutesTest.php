<?php

namespace Tests\Feature\Timeline\Classic;

use App\Models\Member;
use App\Models\TimelinePost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TimelineRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $member = Member::factory()->create();
        $post = TimelinePost::factory()->create(['member_id' => $member->getKey()]);

        $this->get("/member/{$member->getKey()}/timeline")->assertRedirect('/login');
        $this->get("/timeline/{$post->getKey()}")->assertRedirect('/login');
    }

    public function test_member_timeline_renders_with_op3_body_id_and_post_body(): void
    {
        $member = Member::factory()->create();
        TimelinePost::factory()->create(['member_id' => $member->getKey(), 'body' => 'Hello timeline']);

        $response = $this->actingAs($member)->get("/member/{$member->getKey()}/timeline");

        $response->assertOk();
        $response->assertSee('id="page_timeline_member"', false);
        $response->assertSee('Hello timeline');
    }

    public function test_member_timeline_404_when_owner_blocks_viewer(): void
    {
        [$owner, $viewer] = Member::factory()->count(2)->create()->all();
        TimelinePost::factory()->create(['member_id' => $owner->getKey()]);
        DB::table('member_blocks')->insert([
            'blocker_id' => $owner->getKey(),
            'blocked_id' => $viewer->getKey(),
        ]);

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}/timeline")->assertNotFound();
    }

    public function test_show_renders_with_op3_body_id(): void
    {
        $member = Member::factory()->create();
        $post = TimelinePost::factory()->create(['member_id' => $member->getKey(), 'body' => 'Permalinked']);

        $response = $this->actingAs($member)->get("/timeline/{$post->getKey()}");

        $response->assertOk();
        $response->assertSee('id="page_timeline_show"', false);
        $response->assertSee('Permalinked');
    }

    public function test_show_returns_404_for_non_viewable_post(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create()->all();
        $post = TimelinePost::factory()->private()->create(['member_id' => $bob->getKey()]);

        $this->actingAs($alice)->get("/timeline/{$post->getKey()}")->assertNotFound();
    }
}
