<?php

declare(strict_types=1);

namespace Tests\Feature\Member\Actions;

use App\Features\Group\Actions\AcceptAdminTransfer;
use App\Features\Group\Actions\JoinGroup;
use App\Features\Group\Actions\RequestAdminTransfer;
use App\Features\Group\GroupRole;
use App\Features\Member\Actions\WithdrawMember;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\DiaryImage;
use App\Models\DirectMessage;
use App\Models\DirectMessageRecipient;
use App\Models\File;
use App\Models\Group;
use App\Models\GroupMember;
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
        $membership = GroupMember::factory()->create(['member_id' => $member->getKey()]);

        $this->captureSecurityLog();
        $this->withdraw($member);

        $this->assertModelMissing($member);
        $this->assertModelMissing($diary);
        $this->assertModelMissing($membership);

        $context = $this->assertOneSecurityEvent('member.withdrawn');
        $this->assertSame('self', $context['actor']);
    }

    public function test_deletes_personal_access_tokens_the_cascade_cannot_reach(): void
    {
        // `tokenable` is polymorphic, so no foreign key sweeps these. Left behind they would
        // outlive the member and follow a reused member id onto whoever inherits it.
        $member = Member::factory()->create();
        $member->createToken('mcp', ['mcp:read']);
        $bystander = Member::factory()->create();
        $bystander->createToken('mcp', ['mcp:read']);

        $this->withdraw($member);

        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $member->getKey()]);
        $this->assertSame(1, $bystander->tokens()->count());
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
        $group = Group::factory()->create();
        GroupMember::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $admin->getKey(),
            'role' => GroupRole::Admin,
        ]);
        // Two ordinary members; the oldest membership row should be promoted.
        $oldest = GroupMember::factory()->create([
            'group_id' => $group->getKey(),
            'role' => GroupRole::Member,
        ]);
        GroupMember::factory()->create([
            'group_id' => $group->getKey(),
            'role' => GroupRole::Member,
        ]);

        $this->withdraw($admin);

        $this->assertModelExists($group);
        $oldest->refresh();
        $this->assertSame(GroupRole::Admin, $oldest->role);
    }

    public function test_sub_admin_is_not_treated_as_admin_and_is_promoted(): void
    {
        $admin = Member::factory()->create();
        $group = Group::factory()->create();
        GroupMember::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $admin->getKey(),
            'role' => GroupRole::Admin,
        ]);
        // A SubAdmin is not an admin: with the sole Admin leaving, it must be promoted, not skipped.
        $subAdmin = GroupMember::factory()->create([
            'group_id' => $group->getKey(),
            'role' => GroupRole::SubAdmin,
        ]);

        $this->withdraw($admin);

        $this->assertModelExists($group);
        $subAdmin->refresh();
        $this->assertSame(GroupRole::Admin, $subAdmin->role);
    }

    public function test_sole_admin_community_with_no_other_members_is_deleted(): void
    {
        $admin = Member::factory()->create();
        $group = Group::factory()->create();
        GroupMember::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $admin->getKey(),
            'role' => GroupRole::Admin,
        ]);

        $this->withdraw($admin);

        $this->assertModelMissing($group);
    }

    public function test_community_with_another_admin_is_kept_without_promotion(): void
    {
        $leaving = Member::factory()->create();
        $group = Group::factory()->create();
        GroupMember::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $leaving->getKey(),
            'role' => GroupRole::Admin,
        ]);
        $other = GroupMember::factory()->create([
            'group_id' => $group->getKey(),
            'role' => GroupRole::Admin,
        ]);

        $this->withdraw($leaving);

        $this->assertModelExists($group);
        $other->refresh();
        $this->assertSame(GroupRole::Admin, $other->role); // unchanged
    }

    public function test_withdrawing_a_pending_nominee_clears_pending_and_removes_every_membership(): void
    {
        // The withdrawing member is a plain member of two groups and the admin-transfer nominee of
        // one. All memberships go through the locked leave path (not the FK cascade), and the dangling
        // pending seat is cleared under the same lock.
        $leaving = Member::factory()->create();

        $nominated = Group::factory()->create();
        $admin = Member::factory()->create();
        GroupMember::factory()->create(['group_id' => $nominated->getKey(), 'member_id' => $admin->getKey(), 'role' => GroupRole::Admin]);
        $seatA = GroupMember::factory()->create(['group_id' => $nominated->getKey(), 'member_id' => $leaving->getKey(), 'role' => GroupRole::Member]);
        $nominated->forceFill(['pending_admin_member_id' => $leaving->getKey()])->save();

        $other = Group::factory()->create();
        GroupMember::factory()->create(['group_id' => $other->getKey(), 'role' => GroupRole::Admin]);
        $seatB = GroupMember::factory()->create(['group_id' => $other->getKey(), 'member_id' => $leaving->getKey(), 'role' => GroupRole::Member]);

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

        $group = Group::factory()->create(); // Open by default
        $a = Member::factory()->create();
        GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $a->getKey(), 'role' => GroupRole::Admin]);

        $injected = false;
        Diary::deleted(function () use (&$injected, $a, $b, $group): void {
            if ($injected) {
                return;
            }
            $injected = true;

            (new JoinGroup)($b, $group);
            (new RequestAdminTransfer)($a, $group, $b);
            (new AcceptAdminTransfer)($b, $group);
        });

        $this->withdraw($b);

        $this->assertTrue($injected, 'the mid-withdrawal join/transfer was never exercised');
        $this->assertDatabaseMissing('members', ['id' => $b->getKey()]);
        $this->assertDatabaseMissing('group_members', ['member_id' => $b->getKey()]);
        $this->assertNull($group->fresh()->pending_admin_member_id);

        $admins = GroupMember::query()
            ->where('group_id', $group->getKey())
            ->where('role', GroupRole::Admin->value)
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
