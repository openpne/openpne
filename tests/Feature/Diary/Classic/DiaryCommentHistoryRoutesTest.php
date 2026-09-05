<?php

namespace Tests\Feature\Diary\Classic;

use App\Features\Diary\Queries\DiaryCommentHistory;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Only the page's own shape is asserted here (OpenPNE 3 diaryComment/history, historySuccess.php):
 * set and order come from the same builder as the home box, so the membership rules are the query's
 * own tests.
 */
class DiaryCommentHistoryRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get('/diary/comment/history')->assertRedirect('/login');
    }

    public function test_the_page_lists_a_commented_diary_with_count_author_and_body_id(): void
    {
        $viewer = Member::factory()->create();
        $owner = Member::factory()->create(['name' => 'HistoryAuthor']);
        $diary = Diary::factory()->create(['member_id' => $owner->getKey(), 'title' => 'HistoryDiary', 'visibility' => Visibility::Members]);
        DiaryComment::factory()->create(['diary_id' => $diary->getKey(), 'member_id' => $viewer->getKey()]);

        $response = $this->actingAs($viewer)->get('/diary/comment/history')->assertOk();

        // OpenPNE 3 body id: module diaryComment, action history.
        $response->assertSee('id="page_diaryComment_history"', false)
            ->assertSee('class="dparts recentList"', false)
            // op_diary_link_to_show(diary, withName, no icon): "title (count)" linked, then the author.
            ->assertSee('<a href="'.route('diary.show', $diary).'">HistoryDiary (1)</a> (HistoryAuthor)', false);
    }

    /** op_diary_get_title_and_count: display width 36, full-width counting double, no ellipsis. */
    public function test_a_long_title_is_cut_to_openpne3s_display_width(): void
    {
        $viewer = Member::factory()->create();
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create([
            'member_id' => $owner->getKey(),
            'title' => str_repeat('あ', 30), // width 60, cut at 36 → 18 chars
            'visibility' => Visibility::Members,
        ]);
        DiaryComment::factory()->create(['diary_id' => $diary->getKey(), 'member_id' => $viewer->getKey()]);

        $this->actingAs($viewer)->get('/diary/comment/history')
            ->assertOk()
            ->assertSee('>'.str_repeat('あ', 18).' (1)</a>', false)
            ->assertDontSee(str_repeat('あ', 19));
    }

    public function test_the_pager_brackets_the_list_and_pages_by_twenty(): void
    {
        $viewer = Member::factory()->create();
        $owner = Member::factory()->create();
        foreach (range(1, 21) as $n) {
            $diary = Diary::factory()->create(['member_id' => $owner->getKey(), 'title' => "Paged {$n}", 'visibility' => Visibility::Members]);
            DiaryComment::factory()->create(['diary_id' => $diary->getKey(), 'member_id' => $viewer->getKey()]);
        }

        $content = (string) $this->actingAs($viewer)->get('/diary/comment/history')->assertOk()->getContent();

        $this->assertSame(2, substr_count($content, 'class="pagerRelative"'));
        $this->assertSame(20, substr_count($content, '<dl>'));

        $this->actingAs($viewer)->get('/diary/comment/history?page=2')
            ->assertOk()
            ->assertSee('21 - 21 of 21');
    }

    public function test_an_empty_history_shows_the_openpne3_box(): void
    {
        $this->actingAs(Member::factory()->create())->get('/diary/comment/history')
            ->assertOk()
            ->assertSee('id="diaryList"', false)
            ->assertSee(__('There are no %diaries%.'));
    }

    public function test_the_page_shares_the_boxes_set_and_order(): void
    {
        $viewer = Member::factory()->create();
        $owner = Member::factory()->create();
        foreach (['First', 'Second'] as $title) {
            $diary = Diary::factory()->create(['member_id' => $owner->getKey(), 'title' => $title, 'visibility' => Visibility::Members]);
            DiaryComment::factory()->create(['diary_id' => $diary->getKey(), 'member_id' => $viewer->getKey()]);
        }
        $query = app(DiaryCommentHistory::class);

        $this->assertSame(
            $query($viewer, 20)->pluck('id')->all(),
            $query->paginate($viewer)->pluck('id')->all(),
        );
    }

    public function test_a_modern_viewer_is_sent_to_the_notification_feed(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);

        $this->actingAs(Member::factory()->create())->get('/diary/comment/history')
            ->assertRedirect(route('notifications.index'));
    }
}
