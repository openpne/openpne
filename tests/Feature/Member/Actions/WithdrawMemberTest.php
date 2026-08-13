<?php

declare(strict_types=1);

namespace Tests\Feature\Member\Actions;

use App\Features\Community\Actions\AcceptAdminTransfer;
use App\Features\Community\Actions\JoinCommunity;
use App\Features\Community\Actions\RequestAdminTransfer;
use App\Features\Community\CommunityRole;
use App\Features\Member\Actions\WithdrawMember;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\DiaryImage;
use App\Models\DirectMessage;
use App\Models\DirectMessageRecipient;
use App\Models\File;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Models\TimelinePostImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CapturesSecurityLog;
use Tests\TestCase;

class WithdrawMemberTest extends TestCase
{
    use CapturesSecurityLog, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Reserve id 1 as the un-withdrawable primary member so factory subjects below get id >= 2.
        Member::factory()->create(['id' => 1]);
    }

    private function withdraw(Member $member): void
    {
        app(WithdrawMember::class)($member);
    }

    public function test_deletes_the_member_and_cascade_owned_rows(): void
    {
        $member = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $member->getKey()]);
        $membership = CommunityMember::factory()->create(['member_id' => $member->getKey()]);

        $this->captureSecurityLog();
        $this->withdraw($member);

        $this->assertModelMissing($member);
        $this->assertModelMissing($diary);
        $this->assertModelMissing($membership);

        $context = $this->assertOneSecurityEvent('member.withdrawn');
        $this->assertSame('self', $context['actor']);
    }

    public function test_purges_image_bytes_of_owned_diaries_and_timeline_posts(): void
    {
        $member = Member::factory()->create();

        $diary = Diary::factory()->create(['member_id' => $member->getKey()]);
        $diaryImage = DiaryImage::factory()->create(['diary_id' => $diary->getKey()]);
        $post = TimelinePost::factory()->create(['member_id' => $member->getKey()]);
        $postImage = TimelinePostImage::factory()->create(['timeline_post_id' => $post->getKey()]);

        $diaryFile = File::findOrFail($diaryImage->file_id);
        $postFile = File::findOrFail($postImage->file_id);

        $this->withdraw($member);

        $this->assertModelMissing($diaryFile);
        $this->assertModelMissing($postFile);
    }

    public function test_retains_set_null_content_with_a_null_author(): void
    {
        $member = Member::factory()->create();
        $author = Member::factory()->create();

        // The withdrawing member's comment on someone else's diary stays (null author).
        $othersDiary = Diary::factory()->create(['member_id' => $author->getKey()]);
        $comment = DiaryComment::factory()->create([
            'diary_id' => $othersDiary->getKey(),
            'member_id' => $member->getKey(),
        ]);

        // A message they sent stays for the recipient's copy.
        $message = DirectMessage::factory()->create(['sender_id' => $member->getKey()]);
        $receipt = DirectMessageRecipient::factory()->create(['direct_message_id' => $message->getKey()]);

        $this->withdraw($member);

        $this->assertDatabaseHas('diary_comments', ['id' => $comment->getKey(), 'member_id' => null]);
        $this->assertDatabaseHas('direct_messages', ['id' => $message->getKey(), 'sender_id' => null]);
        $this->assertModelExists($receipt);
    }

    public function test_sole_admin_community_with_other_members_hands_over_to_oldest(): void
    {
        $admin = Member::factory()->create();
        $community = Community::factory()->create();
        CommunityMember::factory()->create([
            'community_id' => $community->getKey(),
            'member_id' => $admin->getKey(),
            'role' => CommunityRole::Admin,
        ]);
        // Two ordinary members; the oldest membership row should be promoted.
        $oldest = CommunityMember::factory()->create([
            'community_id' => $community->getKey(),
            'role' => CommunityRole::Member,
        ]);
        CommunityMember::factory()->create([
            'community_id' => $community->getKey(),
            'role' => CommunityRole::Member,
        ]);

        $this->withdraw($admin);

        $this->assertModelExists($community);
        $oldest->refresh();
        $this->assertSame(CommunityRole::Admin, $oldest->role);
    }

    public function test_sub_admin_is_not_treated_as_admin_and_is_promoted(): void
    {
        $admin = Member::factory()->create();
        $community = Community::factory()->create();
        CommunityMember::factory()->create([
            'community_id' => $community->getKey(),
            'member_id' => $admin->getKey(),
            'role' => CommunityRole::Admin,
        ]);
        // A SubAdmin is not an admin: with the sole Admin leaving, it must be promoted, not skipped.
        $subAdmin = CommunityMember::factory()->create([
            'community_id' => $community->getKey(),
            'role' => CommunityRole::SubAdmin,
        ]);

        $this->withdraw($admin);

        $this->assertModelExists($community);
        $subAdmin->refresh();
        $this->assertSame(CommunityRole::Admin, $subAdmin->role);
    }

    public function test_sole_admin_community_with_no_other_members_is_deleted(): void
    {
        $admin = Member::factory()->create();
        $community = Community::factory()->create();
        CommunityMember::factory()->create([
            'community_id' => $community->getKey(),
            'member_id' => $admin->getKey(),
            'role' => CommunityRole::Admin,
        ]);

        $this->withdraw($admin);

        $this->assertModelMissing($community);
    }

    public function test_community_with_another_admin_is_kept_without_promotion(): void
    {
        $leaving = Member::factory()->create();
        $community = Community::factory()->create();
        CommunityMember::factory()->create([
            'community_id' => $community->getKey(),
            'member_id' => $leaving->getKey(),
            'role' => CommunityRole::Admin,
        ]);
        $other = CommunityMember::factory()->create([
            'community_id' => $community->getKey(),
            'role' => CommunityRole::Admin,
        ]);

        $this->withdraw($leaving);

        $this->assertModelExists($community);
        $other->refresh();
        $this->assertSame(CommunityRole::Admin, $other->role); // unchanged
    }

    public function test_withdrawing_a_pending_nominee_clears_pending_and_removes_every_membership(): void
    {
        // The withdrawing member is a plain member of two communities and the admin-transfer nominee of
        // one. All memberships go through the locked leave path (not the FK cascade), and the dangling
        // pending seat is cleared under the same lock.
        $leaving = Member::factory()->create();

        $nominated = Community::factory()->create();
        $admin = Member::factory()->create();
        CommunityMember::factory()->create(['community_id' => $nominated->getKey(), 'member_id' => $admin->getKey(), 'role' => CommunityRole::Admin]);
        $seatA = CommunityMember::factory()->create(['community_id' => $nominated->getKey(), 'member_id' => $leaving->getKey(), 'role' => CommunityRole::Member]);
        $nominated->forceFill(['pending_admin_member_id' => $leaving->getKey()])->save();

        $other = Community::factory()->create();
        CommunityMember::factory()->create(['community_id' => $other->getKey(), 'role' => CommunityRole::Admin]);
        $seatB = CommunityMember::factory()->create(['community_id' => $other->getKey(), 'member_id' => $leaving->getKey(), 'role' => CommunityRole::Member]);

        $this->withdraw($leaving);

        $this->assertModelMissing($seatA);
        $this->assertModelMissing($seatB);
        $this->assertNull($nominated->fresh()->pending_admin_member_id);
        $this->assertModelExists($nominated);
        $this->assertModelExists($other);
    }

    public function test_a_membership_racing_in_mid_withdrawal_cannot_strand_a_community_admin_less(): void
    {
        // Simulate the mid-withdrawal window without real concurrency: a one-shot listener on the diary
        // purge phase makes the withdrawing member B join community C, get nominated by admin A, and
        // accept — becoming C's sole admin after the initial community drain has already run. The
        // verify+delete loop must re-drain that membership so C keeps exactly one admin (A), not zero.
        $b = Member::factory()->create();
        Diary::factory()->create(['member_id' => $b->getKey()]);

        $community = Community::factory()->create(); // Open by default
        $a = Member::factory()->create();
        CommunityMember::factory()->create(['community_id' => $community->getKey(), 'member_id' => $a->getKey(), 'role' => CommunityRole::Admin]);

        $injected = false;
        Diary::deleted(function () use (&$injected, $a, $b, $community): void {
            if ($injected) {
                return;
            }
            $injected = true;

            (new JoinCommunity)($b, $community);
            (new RequestAdminTransfer)($a, $community, $b);
            (new AcceptAdminTransfer)($b, $community);
        });

        $this->withdraw($b);

        $this->assertTrue($injected, 'the mid-withdrawal join/transfer was never exercised');
        $this->assertDatabaseMissing('members', ['id' => $b->getKey()]);
        $this->assertDatabaseMissing('community_members', ['member_id' => $b->getKey()]);
        $this->assertNull($community->fresh()->pending_admin_member_id);

        $admins = CommunityMember::query()
            ->where('community_id', $community->getKey())
            ->where('role', CommunityRole::Admin->value)
            ->pluck('member_id');
        $this->assertSame([$a->getKey()], $admins->all());
    }

    public function test_primary_member_cannot_be_withdrawn(): void
    {
        $primary = Member::findOrFail(1);

        try {
            $this->withdraw($primary);
            $this->fail('Expected the primary member to be un-withdrawable.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertModelExists($primary);
    }
}
