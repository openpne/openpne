<?php

namespace Tests\Feature\Diary\Classic;

use App\Features\Member\Actions\SetAvatar;
use App\Models\Diary;
use App\Models\Member;
use App\Support\Visibility;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DiaryFeedRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_friend_feed_still_redirects_a_guest_to_login(): void
    {
        // The all-member feed is guest-reachable on its web-public tier; the friend feed is defined
        // by a viewer's friendships, so it stays behind the login.
        $this->get('/diary/listFriend')->assertRedirect('/login');
    }

    /**
     * A web-public entry belongs in the member feed, as in OpenPNE 3: an Open save is stored
     * there as public_flag=1 + is_open, which is exactly what its feed query lists.
     */
    public function test_the_member_feed_includes_web_public_entries(): void
    {
        $viewer = Member::factory()->create();
        Diary::factory()->create(['title' => 'Open note', 'visibility' => Visibility::Open]);
        Diary::factory()->create(['title' => 'Members note', 'visibility' => Visibility::Members]);
        Diary::factory()->create(['title' => 'Friends note', 'visibility' => Visibility::Friends]);

        $this->actingAs($viewer)->get('/diary/list')
            ->assertOk()
            ->assertSee('Open note')
            ->assertSee('Members note')
            ->assertDontSee('Friends note');
    }

    /** OpenPNE 3's all-member list carries only the all-members tier, your own entries included. */
    public function test_the_member_feed_excludes_the_viewers_own_restricted_entries(): void
    {
        $viewer = Member::factory()->create();
        Diary::factory()->create(['member_id' => $viewer->getKey(), 'title' => 'My friends note', 'visibility' => Visibility::Friends]);
        Diary::factory()->create(['member_id' => $viewer->getKey(), 'title' => 'My private note', 'visibility' => Visibility::Private]);
        Diary::factory()->create(['member_id' => $viewer->getKey(), 'title' => 'My members note', 'visibility' => Visibility::Members]);

        $this->actingAs($viewer)->get('/diary/list')
            ->assertOk()
            ->assertSee('My members note')
            ->assertDontSee('My friends note')
            ->assertDontSee('My private note');
    }

    /**
     * The friend feed spans every tier a friend opens to friends — Open and Members included, as
     * OpenPNE 3's friend list (public_flag IN (1,2)) did — and nothing from anyone else.
     */
    public function test_the_friend_feed_spans_the_friends_tiers_and_no_one_elses(): void
    {
        $viewer = Member::factory()->create();
        $friend = Member::factory()->create();
        $stranger = Member::factory()->create();
        DB::table('friendships')->insert([
            ['member_id' => $viewer->getKey(), 'friend_id' => $friend->getKey()],
            ['member_id' => $friend->getKey(), 'friend_id' => $viewer->getKey()],
        ]);
        foreach ([
            ['Friend open note', Visibility::Open],
            ['Friend members note', Visibility::Members],
            ['Friend friends note', Visibility::Friends],
            ['Friend private note', Visibility::Private],
        ] as [$title, $visibility]) {
            Diary::factory()->create(['member_id' => $friend->getKey(), 'title' => $title, 'visibility' => $visibility]);
        }
        Diary::factory()->create(['member_id' => $stranger->getKey(), 'title' => 'Stranger members note', 'visibility' => Visibility::Members]);

        $this->actingAs($viewer)->get('/diary/listFriend')
            ->assertOk()
            ->assertSee('Friend open note')
            ->assertSee('Friend members note')
            ->assertSee('Friend friends note')
            ->assertDontSee('Friend private note')
            ->assertDontSee('Stranger members note');
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

    public function test_recent_feed_renders_the_no_image_fallback_when_the_author_has_no_avatar(): void
    {
        $viewer = Member::factory()->create();
        $author = Member::factory()->create();
        Diary::factory()->create([
            'member_id' => $author->getKey(),
            'visibility' => Visibility::Members,
        ]);

        // OpenPNE 3 parity: the photo link still renders, showing the no_image.gif fallback.
        $this->actingAs($viewer)->get('/diary/list')
            ->assertOk()
            ->assertSee('class="photo"', false)
            ->assertSee('images/no_image.gif', false);
    }

    public function test_recent_feed_draws_the_openpne3_result_band(): void
    {
        $viewer = Member::factory()->create();
        $author = Member::factory()->create(['name' => 'Author']);
        $diary = Diary::factory()->create([
            'member_id' => $author->getKey(),
            'title' => 'Hello world',
            'visibility' => Visibility::Members,
        ]);

        $response = $this->actingAs($viewer)->get('/diary/list')->assertOk();

        // listSuccess.php hand-writes the band: a fixed rowspan-4 photo cell, then nickname / title /
        // body rows, closed by tr.operation pairing the datetime with the entry link.
        $response->assertSee('<div class="ditem"><div class="item"><table><tbody>', false);
        $response->assertSee('<td rowspan="4" class="photo">', false);
        $response->assertSee('<tr class="operation">', false);
        $response->assertSee('<span class="moreInfo"><a href="'.route('diary.show', $diary).'">View this diary</a></span>', false);
        // The title cell prints unlinked — the photo cell and the operation row carry the links.
        $response->assertSee('<th>Title</th><td>Hello world (0)<', false);
        $response->assertDontSee('>Hello world (0)</a>', false);
        // The pager brackets the list, as op_include_pager_navigation does above and below the block.
        $this->assertSame(2, substr_count((string) $response->getContent(), 'class="pagerRelative"'));
    }

    public function test_friend_feed_draws_the_openpne3_recent_list(): void
    {
        $viewer = Member::factory()->create();
        $friend = Member::factory()->create(['name' => 'Fran']);
        DB::table('friendships')->insert([
            ['member_id' => $viewer->getKey(), 'friend_id' => $friend->getKey()],
            ['member_id' => $friend->getKey(), 'friend_id' => $viewer->getKey()],
        ]);
        $diary = Diary::factory()->create([
            'member_id' => $friend->getKey(),
            'title' => 'Friend entry',
            'visibility' => Visibility::Friends,
            'created_at' => CarbonImmutable::create(2026, 6, 4, 13, 44),
        ]);

        $response = $this->actingAs($viewer)->withSession(['locale' => 'ja'])->get('/diary/listFriend')->assertOk();

        // listFriendSuccess.php: one dl per entry, op_format_date(XDateTimeJa) in the dt and
        // op_diary_link_to_show in the dd — the author trails the link rather than sitting inside it.
        $response->assertSee('<dt>2026年06月04日 13:44</dt>', false);
        $response->assertSee('<dd><a href="'.route('diary.show', $diary).'">Friend entry (0)</a> (Fran)</dd>', false);
        // The pager brackets the list, as op_include_pager_navigation does above and below it.
        $this->assertSame(2, substr_count((string) $response->getContent(), 'class="pagerRelative"'));
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

        // OpenPNE 3 listSuccess.php swaps the result list for its own #diaryList box when empty.
        $this->actingAs($viewer)->get('/diary/list')
            ->assertOk()
            ->assertSee('id="diaryList"', false)
            ->assertDontSee('id="diary_feed"', false);
    }
}
