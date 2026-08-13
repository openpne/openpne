<?php

namespace Tests\Feature\GroupEvent;

use App\Features\Group\GroupRole;
use App\Features\GroupEvent\GroupEventAccess;
use App\Features\GroupTopic\TopicPostAuthority;
use App\Features\GroupTopic\TopicReadAccess;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroupEventAccessTest extends TestCase
{
    use RefreshDatabase;

    private function joined(Group $group, GroupRole $role): Member
    {
        $member = Member::factory()->create();
        GroupMember::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
            'role' => $role,
        ]);

        return $member;
    }

    public function test_read_access_everyone_admits_any_signed_in_member(): void
    {
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::Everyone]);
        $member = $this->joined($group, GroupRole::Member);
        $stranger = Member::factory()->create();

        $this->assertTrue(GroupEventAccess::canViewBoard($group, $member));
        $this->assertTrue(GroupEventAccess::canViewBoard($group, $stranger));
    }

    public function test_read_access_members_only_excludes_non_members(): void
    {
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        $member = $this->joined($group, GroupRole::Member);
        $stranger = Member::factory()->create();

        $this->assertTrue(GroupEventAccess::canViewBoard($group, $member));
        $this->assertFalse(GroupEventAccess::canViewBoard($group, $stranger));
    }

    public function test_post_authority_members_lets_any_member_create(): void
    {
        $group = Group::factory()->create(['topic_post_authority' => TopicPostAuthority::Members]);
        $member = $this->joined($group, GroupRole::Member);
        $stranger = Member::factory()->create();

        $this->assertTrue(GroupEventAccess::canPostEvent($group, $member));
        $this->assertFalse(GroupEventAccess::canPostEvent($group, $stranger));
    }

    public function test_post_authority_admins_only_limits_creating_to_admins(): void
    {
        $group = Group::factory()->create(['topic_post_authority' => TopicPostAuthority::AdminsOnly]);
        $admin = $this->joined($group, GroupRole::Admin);
        $member = $this->joined($group, GroupRole::Member);

        $this->assertTrue(GroupEventAccess::canPostEvent($group, $admin));
        $this->assertFalse(GroupEventAccess::canPostEvent($group, $member));
    }

    public function test_admins_only_community_still_lets_members_comment_and_participate(): void
    {
        $group = Group::factory()->create(['topic_post_authority' => TopicPostAuthority::AdminsOnly]);
        $admin = $this->joined($group, GroupRole::Admin);
        $member = $this->joined($group, GroupRole::Member);
        $stranger = Member::factory()->create();
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'member_id' => $admin->getKey()]);

        $this->assertTrue(GroupEventAccess::canComment($event, $member));
        $this->assertTrue(GroupEventAccess::canParticipate($event, $member));
        $this->assertFalse(GroupEventAccess::canComment($event, $stranger));
        $this->assertFalse(GroupEventAccess::canParticipate($event, $stranger));
    }

    public function test_event_is_editable_by_its_author_a_member_and_by_admins(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group, GroupRole::Member);
        $admin = $this->joined($group, GroupRole::Admin);
        $otherMember = $this->joined($group, GroupRole::Member);
        $stranger = Member::factory()->create();
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);

        $this->assertTrue(GroupEventAccess::canEditEvent($event, $author));
        $this->assertTrue(GroupEventAccess::canEditEvent($event, $admin));
        $this->assertFalse(GroupEventAccess::canEditEvent($event, $otherMember));
        $this->assertFalse(GroupEventAccess::canEditEvent($event, $stranger));
    }

    public function test_an_author_who_left_the_community_can_no_longer_edit(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group, GroupRole::Member);
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);

        GroupMember::query()
            ->where('group_id', $group->getKey())
            ->where('member_id', $author->getKey())
            ->delete();

        $this->assertFalse(GroupEventAccess::canEditEvent($event->fresh(), $author));
    }

    public function test_comment_is_deletable_by_its_author_the_event_author_and_admins(): void
    {
        $group = Group::factory()->create();
        $eventAuthor = $this->joined($group, GroupRole::Member);
        $admin = $this->joined($group, GroupRole::Admin);
        $commenter = $this->joined($group, GroupRole::Member);
        $otherMember = $this->joined($group, GroupRole::Member);
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'member_id' => $eventAuthor->getKey()]);
        $comment = GroupEventComment::factory()->create([
            'group_event_id' => $event->getKey(),
            'member_id' => $commenter->getKey(),
        ]);

        $this->assertTrue(GroupEventAccess::canDeleteComment($comment, $commenter));
        $this->assertTrue(GroupEventAccess::canDeleteComment($comment, $eventAuthor));
        $this->assertTrue(GroupEventAccess::canDeleteComment($comment, $admin));
        $this->assertFalse(GroupEventAccess::canDeleteComment($comment, $otherMember));
    }

    public function test_a_withdrawn_commenter_cannot_be_impersonated_for_deletion(): void
    {
        $group = Group::factory()->create();
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey()]);
        // member_id null = the commenter withdrew; an ordinary member is not its author.
        $comment = GroupEventComment::factory()->create([
            'group_event_id' => $event->getKey(),
            'member_id' => null,
        ]);
        $member = $this->joined($group, GroupRole::Member);

        $this->assertFalse(GroupEventAccess::canDeleteComment($comment->fresh(), $member));
    }
}
