<?php

namespace Tests\Feature\Timeline\Classic;

use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TimelineHomeFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/timeline')->assertRedirect('/login');
    }

    public function test_home_feed_renders_with_op3_body_id_and_a_visible_post(): void
    {
        $member = Member::factory()->create();
        TimelinePost::factory()->create([
            'member_id' => $member->getKey(),
            'visibility' => Visibility::Members,
            'body' => 'On the home feed',
        ]);

        $response = $this->actingAs($member)->get('/timeline');

        $response->assertOk();
        $response->assertSee('id="page_timeline_sns"', false);
        $response->assertSee('On the home feed');
    }

    public function test_home_feed_shows_cross_member_posts_under_the_viewers_clearance(): void
    {
        [$viewer, $friend, $stranger] = Member::factory()->count(3)->create()->all();
        DB::table('friendships')->insert([
            ['member_id' => $viewer->getKey(), 'friend_id' => $friend->getKey()],
            ['member_id' => $friend->getKey(), 'friend_id' => $viewer->getKey()],
        ]);
        TimelinePost::factory()->friends()->create(['member_id' => $friend->getKey(), 'body' => 'Friend only post']);
        TimelinePost::factory()->create(['member_id' => $stranger->getKey(), 'visibility' => Visibility::Members, 'body' => 'Stranger members post']);
        TimelinePost::factory()->private()->create(['member_id' => $stranger->getKey(), 'body' => 'Stranger secret']);

        $response = $this->actingAs($viewer)->get('/timeline');

        $response->assertSee('Friend only post');
        $response->assertSee('Stranger members post');
        $response->assertDontSee('Stranger secret');
    }

    public function test_delete_control_shows_only_on_the_viewers_own_posts(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create()->all();
        $own = TimelinePost::factory()->create(['member_id' => $viewer->getKey(), 'visibility' => Visibility::Members]);
        $theirs = TimelinePost::factory()->create(['member_id' => $other->getKey(), 'visibility' => Visibility::Members]);

        $response = $this->actingAs($viewer)->get('/timeline');

        $response->assertSee("/timeline/deleteConfirm/{$own->getKey()}", false);
        $response->assertDontSee("/timeline/deleteConfirm/{$theirs->getKey()}", false);
    }

    public function test_op3_sns_timeline_url_redirects_to_the_canonical_feed(): void
    {
        // OpenPNE 3's SNS-wide timeline lived at /sns/timeline; preserve that URL.
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/sns/timeline')->assertRedirect('/timeline');
    }
}
