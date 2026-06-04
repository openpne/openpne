<?php

namespace Tests\Feature\Diary\Classic;

use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks the OpenPNE 3 diaryComment list pager on diary.show: the reversible default (newest
 * page, listed oldest-first), the Older/Newer navigation, and the size/order toggles.
 */
class DiaryCommentPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_view_shows_the_newest_page_with_older_nav_and_toggles(): void
    {
        $owner = Member::factory()->create();
        $diary = $this->diaryWithComments($owner, 25);

        $response = $this->actingAs($owner)->get("/diary/{$diary->getKey()}");

        $response->assertOk();
        $response->assertSee('class="pagerRelative"', false);
        $response->assertSee('No. 6 - 25');           // newest 20, by comment number
        $response->assertSee('View 100 per page');     // size switch
        $response->assertSee('View Oldest First');     // order toggle (default is DESC)
        $response->assertSee('>Older</a>', false);     // older page available
        $response->assertDontSee('>Newer</a>', false); // already on the newest page
    }

    public function test_older_page_shows_the_earliest_comments_with_newer_nav(): void
    {
        $owner = Member::factory()->create();
        $diary = $this->diaryWithComments($owner, 25);

        $this->actingAs($owner)->get("/diary/{$diary->getKey()}?page=2")
            ->assertOk()
            ->assertSee('No. 1 - 5')
            ->assertSee('>Newer</a>', false)
            ->assertDontSee('>Older</a>', false);
    }

    public function test_ascending_order_walks_from_the_first_comment(): void
    {
        $owner = Member::factory()->create();
        $diary = $this->diaryWithComments($owner, 25);

        $this->actingAs($owner)->get("/diary/{$diary->getKey()}?order=asc")
            ->assertOk()
            ->assertSee('No. 1 - 20')
            ->assertSee('View Latest'); // toggle back to newest-first
    }

    public function test_a_short_thread_renders_without_a_pager(): void
    {
        $owner = Member::factory()->create();
        $diary = $this->diaryWithComments($owner, 3);

        $this->actingAs($owner)->get("/diary/{$diary->getKey()}")
            ->assertOk()
            ->assertSee('id="diary_comment_list"', false)
            ->assertDontSee('pagerRelative', false);
    }

    private function diaryWithComments(Member $owner, int $count): Diary
    {
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);
        for ($number = 1; $number <= $count; $number++) {
            DiaryComment::factory()->for($diary)->create(['number' => $number]);
        }

        return $diary;
    }
}
