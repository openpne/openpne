<?php

namespace Tests\Feature\Diary\Queries;

use App\Features\Diary\Queries\DiaryCommentHistory;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The derived OpenPNE 3 diary comment history: other members' diaries the viewer commented on. */
class DiaryCommentHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function makeFriends(Member $a, Member $b): void
    {
        DB::table('friendships')->insert([
            ['member_id' => $a->getKey(), 'friend_id' => $b->getKey()],
            ['member_id' => $b->getKey(), 'friend_id' => $a->getKey()],
        ]);
    }

    private function comment(Diary $diary, Member $author, string $at): void
    {
        DiaryComment::factory()->create([
            'diary_id' => $diary->getKey(),
            'member_id' => $author->getKey(),
            'created_at' => $at,
        ]);
    }

    public function test_only_non_owner_diaries_the_viewer_commented_on_surface(): void
    {
        $viewer = Member::factory()->create();
        $owner = Member::factory()->create();

        $own = Diary::factory()->create(['member_id' => $viewer->getKey(), 'visibility' => Visibility::Members]);
        $this->comment($own, $viewer, '2026-01-01 00:00:00'); // own diary, own comment — excluded

        $uncommented = Diary::factory()->create(['member_id' => $owner->getKey(), 'visibility' => Visibility::Members]);
        $this->comment($uncommented, $owner, '2026-01-01 00:00:00'); // viewer never commented — excluded

        $commented = Diary::factory()->create(['member_id' => $owner->getKey(), 'visibility' => Visibility::Members]);
        $this->comment($commented, $viewer, '2026-01-01 00:00:00');

        $ids = (new DiaryCommentHistory)($viewer)->pluck('id');

        $this->assertSame([$commented->getKey()], $ids->all());
    }

    public function test_orders_by_latest_non_owner_comment_and_owner_followups_do_not_bump(): void
    {
        $viewer = Member::factory()->create();
        $owner = Member::factory()->create();
        $other = Member::factory()->create();

        $a = Diary::factory()->create(['member_id' => $owner->getKey(), 'visibility' => Visibility::Members]);
        $b = Diary::factory()->create(['member_id' => $owner->getKey(), 'visibility' => Visibility::Members]);

        $this->comment($a, $viewer, '2026-01-01 10:00:00');
        $this->comment($b, $viewer, '2026-01-02 10:00:00'); // b's last non-owner comment is later than a's

        $this->comment($a, $other, '2026-03-01 10:00:00'); // another non-owner bumps a above b
        $this->comment($b, $owner, '2026-04-01 10:00:00'); // owner's own follow-up must NOT bump b

        $result = (new DiaryCommentHistory)($viewer);

        $this->assertSame([$a->getKey(), $b->getKey()], $result->pluck('id')->all());
        $this->assertSame('2026-03-01 10:00:00', $result->firstWhere('id', $a->getKey())->last_comment_time);
        $this->assertSame('2026-01-02 10:00:00', $result->firstWhere('id', $b->getKey())->last_comment_time);
    }

    public function test_a_withdrawn_authors_null_comment_still_counts_as_non_owner(): void
    {
        // Withdrawal null-fills diary_comments.member_id (nullOnDelete). On a surviving diary that is
        // necessarily a non-owner comment; a bare != owner drops the NULL row under SQL three-valued
        // logic and would rewind the box's time after the author withdraws.
        $viewer = Member::factory()->create();
        $owner = Member::factory()->create();
        $withdrawn = Member::factory()->create();

        $a = Diary::factory()->create(['member_id' => $owner->getKey(), 'visibility' => Visibility::Members]);
        $b = Diary::factory()->create(['member_id' => $owner->getKey(), 'visibility' => Visibility::Members]);

        $this->comment($a, $viewer, '2026-01-01 10:00:00');
        $this->comment($b, $viewer, '2026-02-01 10:00:00');
        $this->comment($a, $withdrawn, '2026-03-01 10:00:00'); // later than b's; must keep ranking a first
        DiaryComment::where('member_id', $withdrawn->getKey())->update(['member_id' => null]);

        $result = (new DiaryCommentHistory)($viewer);

        $this->assertSame([$a->getKey(), $b->getKey()], $result->pluck('id')->all());
        $this->assertSame('2026-03-01 10:00:00', $result->firstWhere('id', $a->getKey())->last_comment_time);
    }

    public function test_visibility_gate(): void
    {
        $viewer = Member::factory()->create();
        $friend = Member::factory()->create();
        $stranger = Member::factory()->create();
        $this->makeFriends($viewer, $friend);

        $friendFriends = Diary::factory()->create(['member_id' => $friend->getKey(), 'visibility' => Visibility::Friends]);
        $strangerFriends = Diary::factory()->create(['member_id' => $stranger->getKey(), 'visibility' => Visibility::Friends]);
        $strangerMembers = Diary::factory()->create(['member_id' => $stranger->getKey(), 'visibility' => Visibility::Members]);
        foreach ([$friendFriends, $strangerFriends, $strangerMembers] as $diary) {
            $this->comment($diary, $viewer, '2026-01-01 00:00:00');
        }

        $ids = (new DiaryCommentHistory)($viewer)->pluck('id')->all();

        $this->assertContains($friendFriends->getKey(), $ids);  // a friend's friends-only diary is visible
        $this->assertContains($strangerMembers->getKey(), $ids); // an all-members diary is visible
        $this->assertNotContains($strangerFriends->getKey(), $ids); // a stranger's friends-only diary is not
    }

    public function test_a_diary_whose_owner_blocks_the_viewer_is_dropped(): void
    {
        $viewer = Member::factory()->create();
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey(), 'visibility' => Visibility::Members]);
        $this->comment($diary, $viewer, '2026-01-01 00:00:00');

        DB::table('member_blocks')->insert(['blocker_id' => $owner->getKey(), 'blocked_id' => $viewer->getKey()]);

        $this->assertTrue((new DiaryCommentHistory)($viewer)->isEmpty());
    }

    public function test_diary_drops_once_the_viewer_deletes_their_comment_even_if_others_remain(): void
    {
        // Divergence from OpenPNE 3, whose subscription table survived comment deletion: the derived
        // form needs a surviving viewer comment, so deleting it drops the diary even though another
        // member's comment (and thus a last_comment_time) still exists.
        $viewer = Member::factory()->create();
        $owner = Member::factory()->create();
        $other = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey(), 'visibility' => Visibility::Members]);

        $this->comment($diary, $viewer, '2026-01-01 00:00:00');
        $this->comment($diary, $other, '2026-02-01 00:00:00');

        $this->assertSame([$diary->getKey()], (new DiaryCommentHistory)($viewer)->pluck('id')->all());

        DiaryComment::where('diary_id', $diary->getKey())->where('member_id', $viewer->getKey())->delete();

        $this->assertTrue((new DiaryCommentHistory)($viewer)->isEmpty());
    }

    public function test_limit(): void
    {
        $viewer = Member::factory()->create();
        $owner = Member::factory()->create();
        foreach (range(1, 3) as $i) {
            $diary = Diary::factory()->create(['member_id' => $owner->getKey(), 'visibility' => Visibility::Members]);
            $this->comment($diary, $viewer, sprintf('2026-01-0%d 00:00:00', $i));
        }

        $this->assertCount(2, (new DiaryCommentHistory)($viewer, 2));
    }
}
