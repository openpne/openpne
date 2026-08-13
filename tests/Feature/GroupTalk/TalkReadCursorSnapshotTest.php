<?php

namespace Tests\Feature\GroupTalk;

use App\Features\Group\Actions\AddAllMembers;
use App\Features\Group\Actions\ApproveMember;
use App\Features\Group\Actions\CreateGroup;
use App\Features\Group\Actions\JoinGroup;
use App\Features\Group\Data\GroupFormData;
use App\Features\Group\JoinPolicy;
use App\Features\GroupTalk\Queries\CountGroupsWithUnreadTalk;
use App\Features\GroupTalk\Queries\UnreadTalkCounts;
use App\Features\GroupTopic\TopicPostAuthority;
use App\Features\GroupTopic\TopicReadAccess;
use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\Member;
use App\Support\SnsSettingKey;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Joining a group means everything already said counts as read. The cursor is snapshotted from the
 * group's newest message at the moment the membership row is written, by every path that writes one.
 */
class TalkReadCursorSnapshotTest extends TalkTestCase
{
    private function cursorOf(Group $group, Member $member): object
    {
        return DB::table('group_members')
            ->where('group_id', $group->getKey())
            ->where('member_id', $member->getKey())
            ->first(['talk_read_at', 'talk_read_message_id']);
    }

    /**
     * The discriminator for the whole design. A bare DB default writes `(now(), 0)`, and a message
     * created in the same wall-clock second has the tuple `(t, id)` with id > 0 — which compares
     * GREATER than `(t, 0)` and would show up as unread the instant someone joined. Only reading the
     * real latest tuple closes that second.
     */
    public function test_a_message_written_in_the_same_second_as_the_join_counts_as_read(): void
    {
        Carbon::setTestNow('2026-08-14 12:00:00');
        $group = $this->group();
        $existing = GroupMessage::factory()->create(['group_id' => $group->getKey()]);
        $joiner = Member::factory()->create();

        app(JoinGroup::class)($joiner, $group);

        $cursor = $this->cursorOf($group, $joiner);
        $this->assertSame((int) $existing->getKey(), (int) $cursor->talk_read_message_id,
            'the cursor must name the newest message, not sit at id 0 in the same second');
        $this->assertSame(0, app(CountGroupsWithUnreadTalk::class)($joiner));
    }

    public function test_an_empty_conversation_snapshots_to_no_message(): void
    {
        $group = $this->group();
        $joiner = Member::factory()->create();

        app(JoinGroup::class)($joiner, $group);

        $this->assertSame(0, (int) $this->cursorOf($group, $joiner)->talk_read_message_id);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function membershipPaths(): array
    {
        return [
            'group creation (the creator)' => ['createGroup'],
            'open join' => ['openJoin'],
            'join-request approval' => ['approveRequest'],
            'bulk add-all-members' => ['addAll'],
        ];
    }

    /**
     * Every path that writes a membership row must go through the snapshot. A path that forgets it
     * falls back to the DB default, which is a second-precision approximation — the point of pinning
     * all four here is that the next one added cannot quietly be the fifth.
     */
    #[DataProvider('membershipPaths')]
    public function test_every_membership_path_snapshots_the_cursor(string $path): void
    {
        Carbon::setTestNow('2026-08-14 12:00:00');
        [$group, $member] = $this->{$path}();

        $cursor = $this->cursorOf($group, $member);
        $newest = GroupMessage::query()->where('group_id', $group->getKey())->orderByDesc('id')->first();

        $this->assertNotNull($cursor, "{$path} did not create a membership row");
        $this->assertSame(
            (int) ($newest?->getKey() ?? 0),
            (int) $cursor->talk_read_message_id,
            "{$path} left the cursor at the DB default instead of snapshotting the group's newest message",
        );
    }

    /** @return array{0: Group, 1: Member} */
    private function createGroup(): array
    {
        $creator = Member::factory()->create();
        $group = app(CreateGroup::class)($creator, new GroupFormData(
            name: 'talk group',
            description: '',
            registerPolicy: JoinPolicy::Open,
            categoryId: null,
            isJoinNotificationEnabled: true,
            topicReadAccess: TopicReadAccess::Everyone,
            topicPostAuthority: TopicPostAuthority::Members,
        ));

        return [$group, $creator];
    }

    /** @return array{0: Group, 1: Member} */
    private function openJoin(): array
    {
        $group = $this->group();
        GroupMessage::factory()->create(['group_id' => $group->getKey()]);
        $joiner = Member::factory()->create();
        app(JoinGroup::class)($joiner, $group);

        return [$group, $joiner];
    }

    /** @return array{0: Group, 1: Member} */
    private function approveRequest(): array
    {
        $group = Group::factory()->approval()->create();
        GroupMessage::factory()->create(['group_id' => $group->getKey()]);
        $admin = $this->adminOf($group);
        $applicant = Member::factory()->create();
        app(JoinGroup::class)($applicant, $group);
        app(ApproveMember::class)($admin, $group, $applicant);

        return [$group, $applicant];
    }

    /** @return array{0: Group, 1: Member} */
    private function addAll(): array
    {
        $group = $this->group();
        GroupMessage::factory()->create(['group_id' => $group->getKey()]);
        $outsider = Member::factory()->create();
        app(AddAllMembers::class)($group);

        return [$group, $outsider];
    }

    /** Rejoining is a fresh row, so the time away is read: an absence is not a backlog. */
    public function test_leaving_and_rejoining_resets_the_cursor(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        DB::table('group_members')->where('group_id', $group->getKey())->where('member_id', $member->getKey())->delete();

        GroupMessage::factory()->count(3)->create(['group_id' => $group->getKey()]);
        app(JoinGroup::class)($member, $group);

        $this->setSnsSetting(SnsSettingKey::FeatureGroupTalkEnabled, true);
        $this->assertSame([], array_filter(
            array_column(app(UnreadTalkCounts::class)($member), 'count'),
        ), 'messages written while away are read on rejoining');
    }
}
