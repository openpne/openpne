<?php

declare(strict_types=1);

namespace Tests\Feature\Member\Actions;

use App\Features\Group\Actions\AcceptAdminTransfer;
use App\Features\Group\Actions\JoinGroup;
use App\Features\Group\Actions\RequestAdminTransfer;
use App\Features\Group\GroupRole;
use App\Features\Member\Actions\SetAvatar;
use App\Features\Member\Actions\WithdrawMember;
use App\Features\Member\Events\MemberWithdrawn;
use App\Files\MemberFilePurgeException;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use RuntimeException;
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

    /** A member holding the two polymorphic stores no foreign key reaches: their feed and a device. */
    private function memberWithAFeedAndADevice(string $endpoint): Member
    {
        $member = Member::factory()->create();
        $member->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'data' => ['kind' => 'test'],
        ]);
        $member->updatePushSubscription($endpoint, str_repeat('k', 87), str_repeat('a', 22), 'aes128gcm');

        return $member;
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
        // `tokenable` is polymorphic, so no foreign key sweeps these and a reused member id would
        // inherit them.
        $member = Member::factory()->create();
        $member->createToken('mcp', ['mcp:read']);
        $bystander = Member::factory()->create();
        $bystander->createToken('mcp', ['mcp:read']);

        $this->withdraw($member);

        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $member->getKey()]);
        $this->assertSame(1, $bystander->tokens()->count());
    }

    public function test_deletes_the_notification_feed_and_push_subscriptions_the_cascade_cannot_reach(): void
    {
        // Polymorphic for the same reason the tokens are, and carrying the same id-reuse hazard: a
        // surviving subscription would push the next holder of this id's notifications to a
        // stranger's browser.
        $member = $this->memberWithAFeedAndADevice('https://push.example.test/withdrawn');
        $bystander = $this->memberWithAFeedAndADevice('https://push.example.test/bystander');

        $this->withdraw($member);

        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $member->getKey(), 'notifiable_type' => 'member']);
        $this->assertSame(0, $member->pushSubscriptions()->count());
        $this->assertSame(1, $bystander->notifications()->count());
        $this->assertSame(1, $bystander->pushSubscriptions()->count());
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

    private function fileOwnedBy(string $type, int $id, string $name = ''): File
    {
        return File::factory()->create([
            'related_entity_type' => $type,
            'related_entity_id' => $id,
            'name' => $name !== '' ? $name : Str::random(40),
        ]);
    }

    public function test_deletes_every_file_the_member_owns_not_only_the_avatar(): void
    {
        $member = Member::factory()->create();
        $id = (int) $member->getKey();
        app(SetAvatar::class)($member, UploadedFile::fake()->image('me.png', 10, 10));
        $avatar = $member->fresh()->avatar->file;
        // The shapes an upgrade leaves: a second profile photo, and an upload-plugin file named after its owner.
        $photo = $this->fileOwnedBy('member', $id);
        $upload = $this->fileOwnedBy('member', $id, "/m{$id}/1700000000report.pdf");
        $bystanders = $this->fileOwnedBy('member', (int) Member::factory()->create()->getKey());
        // Owner ids are per type, so this file shares the member's number without being theirs.
        $sameNumber = $this->fileOwnedBy('diary', $id);

        $this->withdraw($member);

        $this->assertModelMissing($avatar);
        $this->assertSame(0, DB::table('file_bin')->where('file_id', $avatar->getKey())->count());
        $this->assertModelMissing($photo);
        $this->assertModelMissing($upload);
        $this->assertModelExists($bystanders);
        $this->assertModelExists($sameNumber);
    }

    public function test_deletes_the_files_of_the_members_ai_accounts(): void
    {
        $owner = Member::factory()->create();
        $account = Member::factory()->aiAccount($owner)->create();
        $file = $this->fileOwnedBy('member', (int) $account->getKey());

        $this->withdraw($owner);

        $this->assertModelMissing($account);
        $this->assertModelMissing($file);
    }

    public function test_deletes_past_the_first_page_of_files(): void
    {
        // More rows than one lazyById page, so an offset-paged read that deletes as it goes would skip some.
        $member = Member::factory()->create();
        $now = now();
        DB::table('files')->insert(array_map(fn (int $i): array => [
            'name' => "bulk-{$i}",
            'type' => 'image/png',
            'byte_size' => 0,
            'related_entity_type' => 'member',
            'related_entity_id' => $member->getKey(),
            'created_at' => $now,
            'updated_at' => $now,
        ], range(1, 250)));

        $this->withdraw($member);

        $this->assertSame(0, File::query()->where('related_entity_type', 'member')->where('related_entity_id', $member->getKey())->count());
    }

    public function test_a_file_that_fails_to_delete_is_reported_and_the_rest_of_the_withdrawal_completes(): void
    {
        Exceptions::fake();
        Event::fake([MemberWithdrawn::class]);
        $member = Member::factory()->create();
        $id = (int) $member->getKey();
        $failing = $this->fileOwnedBy('member', $id);
        $others = [$this->fileOwnedBy('member', $id), $this->fileOwnedBy('member', $id)];
        File::deleting(function (File $file) use ($failing): void {
            if ($file->is($failing)) {
                throw new RuntimeException('disk refused');
            }
        });

        $this->captureSecurityLog();
        $this->withdraw($member);

        $this->assertModelMissing($member);
        $this->assertModelExists($failing);
        foreach ($others as $file) {
            $this->assertModelMissing($file);
        }
        Exceptions::assertReported(fn (MemberFilePurgeException $e): bool => str_contains($e->getMessage(), "File [{$failing->getKey()}] of deleted member [{$id}]"));
        $this->assertOneSecurityEvent('member.withdrawn');
        Event::assertDispatched(MemberWithdrawn::class);
    }

    public function test_a_rolled_back_member_delete_keeps_every_member_file(): void
    {
        $member = Member::factory()->create();
        $file = $this->fileOwnedBy('member', (int) $member->getKey());
        // Runs after MemberObserver::deleting has registered the deferred purge, which the rollback must discard.
        Member::deleting(function (): void {
            throw new RuntimeException('boom');
        });

        try {
            DB::transaction(fn () => $member->delete());
            $this->fail('expected the delete to throw');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertModelExists($member);
        $this->assertModelExists($file);
    }

    /** A reply goes with the member row's cascade, which reaches no reaction — the sweep is the action's. */
    public function test_sweeps_the_reactions_others_left_on_the_members_replies(): void
    {
        $member = Member::factory()->create();
        $root = TimelinePost::factory()->create();
        $reply = TimelinePost::factory()->replyTo($root)->create(['member_id' => $member->getKey()]);
        $bystander = Member::factory()->create();
        $reply->reactions()->create(['member_id' => $bystander->getKey(), 'emoji' => "\u{1F44D}"]);
        $root->reactions()->create(['member_id' => $bystander->getKey(), 'emoji' => "\u{1F44D}"]);

        $this->withdraw($member);

        $this->assertDatabaseMissing('timeline_posts', ['id' => $reply->getKey()]);
        $this->assertDatabaseMissing('reactions', ['reactable_id' => $reply->getKey()]);
        $this->assertDatabaseHas('reactions', ['reactable_id' => $root->getKey()]);
    }

    /** A post from another device between the drain and the member lock goes by the cascade, which reaches no reaction. */
    public function test_sweeps_the_reactions_on_a_thread_posted_after_the_drain(): void
    {
        $member = Member::factory()->create();
        $drained = TimelinePost::factory()->create(['member_id' => $member->getKey()]);
        $bystander = Member::factory()->create();
        $late = null;

        // Fires inside the drain's delete of $drained: after the enumeration, before the member lock.
        TimelinePost::deleted(function (TimelinePost $post) use (&$late, $drained, $member, $bystander): void {
            if ($late === null && $post->is($drained)) {
                $late = TimelinePost::factory()->create(['member_id' => $member->getKey()]);
                $reply = TimelinePost::factory()->replyTo($late)->create();
                $late->reactions()->create(['member_id' => $bystander->getKey(), 'emoji' => "\u{1F44D}"]);
                $reply->reactions()->create(['member_id' => $bystander->getKey(), 'emoji' => "\u{1F44D}"]);
            }
        });

        $this->withdraw($member);

        $this->assertNotNull($late);
        $this->assertDatabaseMissing('timeline_posts', ['id' => $late->getKey()]);
        $this->assertDatabaseCount('reactions', 0);
    }

    /** An entry from another device between the drain and the member lock goes by the cascade, which reaches no reaction. */
    public function test_sweeps_the_reactions_on_an_entry_posted_after_the_drain(): void
    {
        $member = Member::factory()->create();
        $drained = Diary::factory()->create(['member_id' => $member->getKey()]);
        $bystander = Member::factory()->create();
        $late = null;

        // Fires inside the drain's delete of $drained: after the enumeration, before the member lock.
        Diary::deleted(function (Diary $diary) use (&$late, $drained, $member, $bystander): void {
            if ($late === null && $diary->is($drained)) {
                $late = Diary::factory()->create(['member_id' => $member->getKey()]);
                $comment = DiaryComment::factory()->create(['diary_id' => $late->getKey()]);
                $late->reactions()->create(['member_id' => $bystander->getKey(), 'emoji' => "\u{1F44D}"]);
                $comment->reactions()->create(['member_id' => $bystander->getKey(), 'emoji' => "\u{1F44D}"]);
            }
        });

        $this->withdraw($member);

        $this->assertNotNull($late);
        $this->assertDatabaseMissing('diaries', ['id' => $late->getKey()]);
        $this->assertDatabaseCount('reactions', 0);
    }

    /** The comment stays with a null author, so what others put on it stays too. */
    public function test_keeps_the_reactions_on_the_members_comment_under_anothers_entry(): void
    {
        $member = Member::factory()->create();
        $comment = DiaryComment::factory()->create([
            'diary_id' => Diary::factory()->create()->getKey(),
            'member_id' => $member->getKey(),
        ]);
        $comment->reactions()->create(['member_id' => Member::factory()->create()->getKey(), 'emoji' => "\u{1F44D}"]);

        $this->withdraw($member);

        $this->assertDatabaseHas('diary_comments', ['id' => $comment->getKey(), 'member_id' => null]);
        $this->assertDatabaseHas('reactions', ['reactable_id' => $comment->getKey(), 'reactable_type' => 'diaryComment']);
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
        // The withdrawing member is a plain member of two groups and the admin-transfer nominee of one.
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
        // Without real concurrency: a one-shot listener on the diary purge phase makes B join C and
        // accept its sole-admin seat after the initial drain has run.
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
        } catch (RuntimeException) {
            // expected
        }

        $this->assertModelExists($primary);
    }
}
