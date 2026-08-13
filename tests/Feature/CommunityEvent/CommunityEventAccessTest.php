<?php

namespace Tests\Feature\CommunityEvent;

use App\Features\CommunityEvent\CommunityEventAccess;
use App\Features\Group\GroupRole;
use App\Features\GroupTopic\TopicPostAuthority;
use App\Features\GroupTopic\TopicReadAccess;
use App\Models\CommunityEvent;
use App\Models\CommunityEventComment;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunityEventAccessTest extends TestCase
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

        $this->assertTrue(CommunityEventAccess::canViewBoard($group, $member));
        $this->assertTrue(CommunityEventAccess::canViewBoard($group, $stranger));
    }

    public function test_read_access_members_only_excludes_non_members(): void
    {
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        $member = $this->joined($group, GroupRole::Member);
        $stranger = Member::factory()->create();

        $this->assertTrue(CommunityEventAccess::canViewBoard($group, $member));
        $this->assertFalse(CommunityEventAccess::canViewBoard($group, $stranger));
    }

    public function test_post_authority_members_lets_any_member_create(): void
    {
        $group = Group::factory()->create(['topic_post_authority' => TopicPostAuthority::Members]);
        $member = $this->joined($group, GroupRole::Member);
        $stranger = Member::factory()->create();

        $this->assertTrue(CommunityEventAccess::canPostEvent($group, $member));
        $this->assertFalse(CommunityEventAccess::canPostEvent($group, $stranger));
    }

    public function test_post_authority_admins_only_limits_creating_to_admins(): void
    {
        $group = Group::factory()->create(['topic_post_authority' => TopicPostAuthority::AdminsOnly]);
        $admin = $this->joined($group, GroupRole::Admin);
        $member = $this->joined($group, GroupRole::Member);

        $this->assertTrue(CommunityEventAccess::canPostEvent($group, $admin));
        $this->assertFalse(CommunityEventAccess::canPostEvent($group, $member));
    }

    public function test_admins_only_community_still_lets_members_comment_and_participate(): void
    {
        $group = Group::factory()->create(['topic_post_authority' => TopicPostAuthority::AdminsOnly]);
        $admin = $this->joined($group, GroupRole::Admin);
        $member = $this->joined($group, GroupRole::Member);
        $stranger = Member::factory()->create();
        $event = CommunityEvent::factory()->create(['community_id' => $group->getKey(), 'member_id' => $admin->getKey()]);

        $this->assertTrue(CommunityEventAccess::canComment($event, $member));
        $this->assertTrue(CommunityEventAccess::canParticipate($event, $member));
        $this->assertFalse(CommunityEventAccess::canComment($event, $stranger));
        $this->assertFalse(CommunityEventAccess::canParticipate($event, $stranger));
    }

    public function test_event_is_editable_by_its_author_a_member_and_by_admins(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group, GroupRole::Member);
        $admin = $this->joined($group, GroupRole::Admin);
        $otherMember = $this->joined($group, GroupRole::Member);
        $stranger = Member::factory()->create();
        $event = CommunityEvent::factory()->create(['community_id' => $group->getKey(), 'member_id' => $author->getKey()]);

        $this->assertTrue(CommunityEventAccess::canEditEvent($event, $author));
        $this->assertTrue(CommunityEventAccess::canEditEvent($event, $admin));
        $this->assertFalse(CommunityEventAccess::canEditEvent($event, $otherMember));
        $this->assertFalse(CommunityEventAccess::canEditEvent($event, $stranger));
    }

    public function test_an_author_who_left_the_community_can_no_longer_edit(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group, GroupRole::Member);
        $event = CommunityEvent::factory()->create(['community_id' => $group->getKey(), 'member_id' => $author->getKey()]);

        GroupMember::query()
            ->where('group_id', $group->getKey())
            ->where('member_id', $author->getKey())
            ->delete();

        $this->assertFalse(CommunityEventAccess::canEditEvent($event->fresh(), $author));
    }

    public function test_comment_is_deletable_by_its_author_the_event_author_and_admins(): void
    {
        $group = Group::factory()->create();
        $eventAuthor = $this->joined($group, GroupRole::Member);
        $admin = $this->joined($group, GroupRole::Admin);
        $commenter = $this->joined($group, GroupRole::Member);
        $otherMember = $this->joined($group, GroupRole::Member);
        $event = CommunityEvent::factory()->create(['community_id' => $group->getKey(), 'member_id' => $eventAuthor->getKey()]);
        $comment = CommunityEventComment::factory()->create([
            'community_event_id' => $event->getKey(),
            'member_id' => $commenter->getKey(),
        ]);

        $this->assertTrue(CommunityEventAccess::canDeleteComment($comment, $commenter));
        $this->assertTrue(CommunityEventAccess::canDeleteComment($comment, $eventAuthor));
        $this->assertTrue(CommunityEventAccess::canDeleteComment($comment, $admin));
        $this->assertFalse(CommunityEventAccess::canDeleteComment($comment, $otherMember));
    }

    public function test_a_withdrawn_commenter_cannot_be_impersonated_for_deletion(): void
    {
        $group = Group::factory()->create();
        $event = CommunityEvent::factory()->create(['community_id' => $group->getKey()]);
        // member_id null = the commenter withdrew; an ordinary member is not its author.
        $comment = CommunityEventComment::factory()->create([
            'community_event_id' => $event->getKey(),
            'member_id' => null,
        ]);
        $member = $this->joined($group, GroupRole::Member);

        $this->assertFalse(CommunityEventAccess::canDeleteComment($comment->fresh(), $member));
    }
}
