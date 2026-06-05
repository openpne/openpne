<?php

namespace Tests\Feature\Diary\Classic;

use App\Features\Member\Actions\SetAvatar;
use App\Models\Diary;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
        // OpenPNE 3's friend feed (listFriendSuccess.php) carries no search form.
        $response->assertDontSee('name="keyword"', false);
    }

    public function test_recent_feed_renders_the_author_avatar_thumbnail(): void
    {
        $viewer = Member::factory()->create();
        $author = Member::factory()->create(['name' => 'Author']);
        app(SetAvatar::class)($author, UploadedFile::fake()->image('a.png', 100, 100));
        Diary::factory()->create([
            'member_id' => $author->getKey(),
            'visibility' => Visibility::Members,
        ]);
        $file = $author->fresh()->avatar->file;

        // OpenPNE 3 listSuccess shows a 76×76 author photo linking to the entry.
        $this->actingAs($viewer)->get('/diary/list')
            ->assertOk()
            ->assertSee($file->thumbnailUrl(76, 76, square: true), false);
    }

    public function test_recent_feed_renders_no_thumbnail_when_the_author_has_no_avatar(): void
    {
        $viewer = Member::factory()->create();
        $author = Member::factory()->create();
        Diary::factory()->create([
            'member_id' => $author->getKey(),
            'visibility' => Visibility::Members,
        ]);

        // No default-avatar placeholder: the photo link is absent when the author has no avatar.
        $this->actingAs($viewer)->get('/diary/list')
            ->assertOk()
            ->assertDontSee('class="photo"', false);
    }

    public function test_friend_feed_omits_the_author_thumbnail(): void
    {
        // OpenPNE 3 listFriendSuccess.php has no author photo, unlike the all-member list.
        $viewer = Member::factory()->create();
        $friend = Member::factory()->create();
        app(SetAvatar::class)($friend, UploadedFile::fake()->image('f.png', 100, 100));
        DB::table('friendships')->insert([
            ['member_id' => $viewer->getKey(), 'friend_id' => $friend->getKey()],
            ['member_id' => $friend->getKey(), 'friend_id' => $viewer->getKey()],
        ]);
        Diary::factory()->create([
            'member_id' => $friend->getKey(),
            'visibility' => Visibility::Friends,
        ]);

        $this->actingAs($viewer)->get('/diary/listFriend')
            ->assertOk()
            ->assertDontSee('class="photo"', false);
    }

    public function test_all_member_feed_carries_the_search_form(): void
    {
        $viewer = Member::factory()->create();

        // OpenPNE 3's all-member list (listSuccess.php) renders the search form inline.
        $this->actingAs($viewer)->get('/diary/list')
            ->assertOk()
            ->assertSee('name="keyword"', false)
            ->assertSee(route('diary.search'), false);
    }

    public function test_empty_feed_shows_placeholder(): void
    {
        $viewer = Member::factory()->create();

        $this->actingAs($viewer)->get('/diary/list')
            ->assertOk()
            ->assertSee('id="diary_feed"', false);
    }
}
