<?php

namespace Tests\Feature\Home;

use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\DiaryImage;
use App\Models\Gadget;
use App\Models\GadgetConfig;
use App\Models\Member;
use App\Services\GadgetService;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The OpenPNE 3 home diary list gadgets: diaryFriendList, diaryList, diaryCommentHistory, diaryMyList. */
class ClassicHomeDiaryGadgetTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, string|int> $config */
    private function makeGadget(string $name, array $config = []): Gadget
    {
        $gadget = Gadget::create(['context' => 'home', 'zone' => 'contents', 'name' => $name, 'sort_order' => 0]);
        foreach ($config as $key => $value) {
            GadgetConfig::create(['gadget_id' => $gadget->id, 'name' => $key, 'value' => (string) $value]);
        }
        app(GadgetService::class)->clearCache();

        return $gadget;
    }

    private function makeFriends(Member $a, Member $b): void
    {
        DB::table('friendships')->insert([
            ['member_id' => $a->getKey(), 'friend_id' => $b->getKey()],
            ['member_id' => $b->getKey(), 'friend_id' => $a->getKey()],
        ]);
    }

    public function test_diary_friend_list_renders_the_openpne3_dom_with_author_and_camera(): void
    {
        $viewer = Member::factory()->create();
        $friend = Member::factory()->create(['name' => 'FriendAuthor']);
        $this->makeFriends($viewer, $friend);
        $diary = Diary::factory()->create(['member_id' => $friend->getKey(), 'title' => 'FriendDiaryTitle', 'visibility' => Visibility::Friends]);
        DiaryImage::factory()->create(['diary_id' => $diary->getKey()]);
        $gadget = $this->makeGadget('diaryFriendList');

        $this->actingAs($viewer)->get('/')
            ->assertOk()
            ->assertSee('id="homeRecentList_'.$gadget->id.'"', false)
            ->assertSee('class="dparts homeRecentList"', false)
            ->assertSee('Recently Posted Diaries of My friends') // h3 (en term rendering)
            ->assertSee('FriendDiaryTitle (0)')                  // title + comment count
            ->assertSee('(FriendAuthor)')                        // author (withName)
            ->assertSee('icon_camera.gif')                       // camera marker (has photos)
            ->assertSee('/diary/listFriend', false);             // More link
    }

    public function test_diary_friend_list_is_dropped_when_empty(): void
    {
        $viewer = Member::factory()->create();
        $this->makeGadget('diaryFriendList');

        // No friends: OpenPNE 3 drops the whole box rather than render an orphan heading.
        $this->actingAs($viewer)->get('/')
            ->assertOk()
            ->assertDontSee('homeRecentList', false);
    }

    public function test_diary_friend_list_ignores_the_host_pages_page_query(): void
    {
        $viewer = Member::factory()->create();
        $friend = Member::factory()->create();
        $this->makeFriends($viewer, $friend);
        Diary::factory()->create(['member_id' => $friend->getKey(), 'title' => 'PageProofDiary', 'visibility' => Visibility::Members]);
        $this->makeGadget('diaryFriendList');

        // take() shows the list regardless of the host page's ?page=.
        $this->actingAs($viewer)->get('/?page=2')
            ->assertOk()
            ->assertSee('PageProofDiary (0)');
    }

    public function test_diary_friend_list_honors_the_max_config(): void
    {
        $viewer = Member::factory()->create();
        $friend = Member::factory()->create();
        $this->makeFriends($viewer, $friend);
        Diary::factory()->create(['member_id' => $friend->getKey(), 'title' => 'OldFriendDiary', 'visibility' => Visibility::Members, 'created_at' => '2026-01-01']);
        Diary::factory()->create(['member_id' => $friend->getKey(), 'title' => 'NewFriendDiary', 'visibility' => Visibility::Members, 'created_at' => '2026-03-01']);
        $this->makeGadget('diaryFriendList', ['max' => 1]);

        $this->actingAs($viewer)->get('/')
            ->assertOk()
            ->assertSee('NewFriendDiary (0)')
            ->assertDontSee('OldFriendDiary');
    }

    public function test_diary_list_shows_the_all_members_feed_and_hides_a_strangers_friends_only_diary(): void
    {
        $viewer = Member::factory()->create();
        $author = Member::factory()->create(['name' => 'AllAuthor']);
        $stranger = Member::factory()->create();
        Diary::factory()->create(['member_id' => $author->getKey(), 'title' => 'MembersFeedDiary', 'visibility' => Visibility::Members]);
        Diary::factory()->create(['member_id' => $stranger->getKey(), 'title' => 'StrangerFriendsOnly', 'visibility' => Visibility::Friends]);
        $gadget = $this->makeGadget('diaryList');

        $this->actingAs($viewer)->get('/')
            ->assertOk()
            ->assertSee('id="homeRecentList_'.$gadget->id.'"', false)
            ->assertSee('Recently Posted Diaries of All') // h3
            ->assertSee('MembersFeedDiary (0)')
            ->assertSee('(AllAuthor)')                    // author (withName)
            ->assertDontSee('StrangerFriendsOnly')        // not in the all-members tier
            ->assertSee('/diary/list', false);            // More link
    }

    public function test_diary_my_list_renders_its_frame_and_write_link_even_when_empty(): void
    {
        $viewer = Member::factory()->create();
        $gadget = $this->makeGadget('diaryMyList');

        // The one diary gadget whose frame renders with no entries — the empty state still offers the
        // write link, and shows no "More" link (no navigation is seeded in this test).
        $this->actingAs($viewer)->get('/')
            ->assertOk()
            ->assertSee('id="homeRecentList_'.$gadget->id.'"', false)
            ->assertSee('My diaries')            // h3
            ->assertSee('/diary/new', false)     // always-present write link
            ->assertDontSee('/diary/listMember', false); // no More link without entries
    }

    public function test_japanese_headings_and_more_link_match_openpne3(): void
    {
        $viewer = Member::factory()->create(['locale' => 'ja']);
        $friend = Member::factory()->create();
        $this->makeFriends($viewer, $friend);
        Diary::factory()->create(['member_id' => $friend->getKey(), 'visibility' => Visibility::Members]);
        $this->makeGadget('diaryFriendList');

        // OpenPNE 3 messages.ja.xml: the friend heading joins the term and 最新 with no particle,
        // and More is もっと見る (not もっと読む).
        $this->actingAs($viewer)->get('/')
            ->assertOk()
            ->assertSee('マイフレンド最新日記')
            ->assertSee('もっと見る');
    }

    public function test_diary_my_list_shows_the_more_link_with_entries_and_no_author(): void
    {
        $viewer = Member::factory()->create(['name' => 'SelfAuthor']);
        Diary::factory()->create(['member_id' => $viewer->getKey(), 'title' => 'MyPrivateDiary', 'visibility' => Visibility::Private]);
        $this->makeGadget('diaryMyList');

        $this->actingAs($viewer)->get('/')
            ->assertOk()
            ->assertSee('MyPrivateDiary (0)')             // own private diary is visible to self
            ->assertSee('/diary/listMember', false)       // More link present
            ->assertDontSee('(SelfAuthor)');              // own list carries no author parenthetical
    }

    public function test_diary_comment_history_renders_with_author_no_camera_and_last_comment_date(): void
    {
        $viewer = Member::factory()->create();
        $owner = Member::factory()->create(['name' => 'CommentedAuthor']);
        $diary = Diary::factory()->create(['member_id' => $owner->getKey(), 'title' => 'CommentedDiary', 'visibility' => Visibility::Members, 'created_at' => '2026-01-01 00:00:00']);
        DiaryImage::factory()->create(['diary_id' => $diary->getKey()]);
        // The viewer's non-owner comment: the row date is this comment's time, not the diary's created_at.
        DiaryComment::factory()->create(['diary_id' => $diary->getKey(), 'member_id' => $viewer->getKey(), 'created_at' => '2026-03-04 12:00:00']);
        $gadget = $this->makeGadget('diaryCommentHistory');

        $this->actingAs($viewer)->get('/')
            ->assertOk()
            ->assertSee('id="homeRecentList_'.$gadget->id.'"', false)
            ->assertSee('class="dparts homeRecentList"', false)
            ->assertSee('Diary Comment History')  // h3 (en term rendering)
            ->assertSee('CommentedDiary (1)')     // title + comment count
            ->assertSee('(CommentedAuthor)')      // author (withName)
            ->assertSee('March 4')                // last comment date, not the diary's January 1 created_at
            ->assertDontSee('icon_camera.gif')    // no camera marker for this kind (withIcon=false)
            // OpenPNE 3 _history.php closes the box with More → @diary_comment_history.
            ->assertSee('<a href="'.route('diary.comment.history').'">More</a>', false);
    }

    public function test_diary_comment_history_is_dropped_when_empty(): void
    {
        $viewer = Member::factory()->create();
        $this->makeGadget('diaryCommentHistory');

        $this->actingAs($viewer)->get('/')
            ->assertOk()
            ->assertDontSee('homeRecentList', false);
    }

    public function test_diary_comment_history_japanese_heading(): void
    {
        $viewer = Member::factory()->create(['locale' => 'ja']);
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey(), 'visibility' => Visibility::Members]);
        DiaryComment::factory()->create(['diary_id' => $diary->getKey(), 'member_id' => $viewer->getKey()]);
        $this->makeGadget('diaryCommentHistory');

        $this->actingAs($viewer)->get('/')
            ->assertOk()
            ->assertSee('日記コメント記入履歴');
    }
}
