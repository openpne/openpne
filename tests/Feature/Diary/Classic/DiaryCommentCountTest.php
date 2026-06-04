<?php

namespace Tests\Feature\Diary\Classic;

use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Locks the OpenPNE 3 op_diary_get_title_and_count display: each list/feed entry shows its
 * comment count "(N)" after the title, across the recent, friend, member, and search feeds.
 */
class DiaryCommentCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_recent_feed_appends_the_comment_count(): void
    {
        $member = Member::factory()->create();
        $this->countedDiary($member, 'Recent Entry', Visibility::Members, 2);

        $this->actingAs($member)->get('/diary/list')
            ->assertOk()
            ->assertSee('Recent Entry (2)');
    }

    public function test_friend_feed_appends_the_comment_count(): void
    {
        [$viewer, $friend] = Member::factory()->count(2)->create()->all();
        DB::table('friendships')->insert([
            ['member_id' => $viewer->getKey(), 'friend_id' => $friend->getKey()],
            ['member_id' => $friend->getKey(), 'friend_id' => $viewer->getKey()],
        ]);
        $this->countedDiary($friend, 'Friend Entry', Visibility::Friends, 1);

        $this->actingAs($viewer)->get('/diary/listFriend')
            ->assertOk()
            ->assertSee('Friend Entry (1)');
    }

    public function test_list_member_appends_the_comment_count(): void
    {
        $member = Member::factory()->create();
        $this->countedDiary($member, 'Member Entry', Visibility::Members, 3);

        $this->actingAs($member)->get('/diary/listMember')
            ->assertOk()
            ->assertSee('Member Entry (3)');
    }

    public function test_search_results_append_the_comment_count(): void
    {
        $member = Member::factory()->create();
        $this->countedDiary($member, 'Searchable Topic', Visibility::Members, 2);

        $this->actingAs($member)->get('/diary/search?keyword=Searchable')
            ->assertOk()
            ->assertSee('Searchable Topic (2)');
    }

    public function test_an_uncommented_entry_shows_zero(): void
    {
        $member = Member::factory()->create();
        $this->countedDiary($member, 'Quiet Entry', Visibility::Members, 0);

        $this->actingAs($member)->get('/diary/listMember')
            ->assertOk()
            ->assertSee('Quiet Entry (0)');
    }

    private function countedDiary(Member $owner, string $title, Visibility $visibility, int $comments): Diary
    {
        $diary = Diary::factory()->create([
            'member_id' => $owner->getKey(),
            'title' => $title,
            'visibility' => $visibility,
        ]);
        for ($number = 1; $number <= $comments; $number++) {
            DiaryComment::factory()->for($diary)->create(['number' => $number]);
        }

        return $diary;
    }
}
