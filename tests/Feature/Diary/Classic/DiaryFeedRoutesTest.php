<?php

namespace Tests\Feature\Diary\Classic;

use App\Models\Diary;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DiaryFeedRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/diary/list')->assertRedirect('/login');
        $this->get('/diary/listFriend')->assertRedirect('/login');
    }

    public function test_recent_feed_renders_members_diaries_with_body_id(): void
    {
        $viewer = Member::factory()->create();
        $author = Member::factory()->create(['name' => 'Author']);
        Diary::factory()->create([
            'member_id' => $author->getKey(),
            'title' => 'Hello world',
            'visibility' => Visibility::Members,
        ]);

        $response = $this->actingAs($viewer)->get('/diary/list');

        $response->assertOk();
        // OpenPNE 3 emits page_{module}_{action}; the action is list.
        $response->assertSee('id="page_diary_list"', false);
        $response->assertSee('Hello world');
        $response->assertSee('Author');
    }

    public function test_recent_feed_hides_a_blocking_owners_diary(): void
    {
        $viewer = Member::factory()->create();
        $blocker = Member::factory()->create();
        Diary::factory()->create([
            'member_id' => $blocker->getKey(),
            'title' => 'Hidden entry',
            'visibility' => Visibility::Members,
        ]);
        DB::table('member_blocks')->insert([
            'blocker_id' => $blocker->getKey(),
            'blocked_id' => $viewer->getKey(),
        ]);

        $this->actingAs($viewer)->get('/diary/list')
            ->assertOk()
            ->assertDontSee('Hidden entry');
    }

    public function test_friend_feed_renders_friends_diary_with_body_id(): void
    {
        $viewer = Member::factory()->create();
        $friend = Member::factory()->create();
        DB::table('friendships')->insert([
            ['member_id' => $viewer->getKey(), 'friend_id' => $friend->getKey()],
            ['member_id' => $friend->getKey(), 'friend_id' => $viewer->getKey()],
        ]);
        Diary::factory()->create([
            'member_id' => $friend->getKey(),
            'title' => 'Friend entry',
            'visibility' => Visibility::Friends,
        ]);

        $response = $this->actingAs($viewer)->get('/diary/listFriend');

        $response->assertOk();
        $response->assertSee('id="page_diary_listFriend"', false);
        $response->assertSee('Friend entry');
    }

    public function test_empty_feed_shows_placeholder(): void
    {
        $viewer = Member::factory()->create();

        $this->actingAs($viewer)->get('/diary/list')
            ->assertOk()
            ->assertSee('id="diary_feed"', false);
    }
}
