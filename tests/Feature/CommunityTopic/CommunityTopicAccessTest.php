<?php

namespace Tests\Feature\CommunityTopic;

use App\Features\CommunityTopic\CommunityTopicAccess;
use App\Features\CommunityTopic\TopicPostAuthority;
use App\Features\CommunityTopic\TopicReadAccess;
use App\Features\Group\GroupRole;
use App\Models\CommunityTopic;
use App\Models\CommunityTopicComment;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunityTopicAccessTest extends TestCase
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

        $this->assertTrue(CommunityTopicAccess::canViewBoard($group, $member));
        $this->assertTrue(CommunityTopicAccess::canViewBoard($group, $stranger));
    }

    public function test_read_access_members_only_excludes_non_members(): void
    {
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        $member = $this->joined($group, GroupRole::Member);
        $stranger = Member::factory()->create();

        $this->assertTrue(CommunityTopicAccess::canViewBoard($group, $member));
        $this->assertFalse(CommunityTopicAccess::canViewBoard($group, $stranger));
    }

    public function test_post_authority_members_lets_any_member_post(): void
    {
        $group = Group::factory()->create(['topic_post_authority' => TopicPostAuthority::Members]);
        $member = $this->joined($group, GroupRole::Member);
        $stranger = Member::factory()->create();

        $this->assertTrue(CommunityTopicAccess::canPostTopic($group, $member));
        $this->assertFalse(CommunityTopicAccess::canPostTopic($group, $stranger));
    }

    public function test_post_authority_admins_only_limits_posting_to_admins(): void
    {
        $group = Group::factory()->create(['topic_post_authority' => TopicPostAuthority::AdminsOnly]);
        $admin = $this->joined($group, GroupRole::Admin);
        $member = $this->joined($group, GroupRole::Member);

        $this->assertTrue(CommunityTopicAccess::canPostTopic($group, $admin));
        $this->assertFalse(CommunityTopicAccess::canPostTopic($group, $member));
    }

    public function test_admins_only_board_still_lets_members_comment(): void
    {
        $group = Group::factory()->create(['topic_post_authority' => TopicPostAuthority::AdminsOnly]);
        $admin = $this->joined($group, GroupRole::Admin);
        $member = $this->joined($group, GroupRole::Member);
        $stranger = Member::factory()->create();
        $topic = CommunityTopic::factory()->create(['community_id' => $group->getKey(), 'member_id' => $admin->getKey()]);

        $this->assertTrue(CommunityTopicAccess::canComment($topic, $member));
        $this->assertFalse(CommunityTopicAccess::canComment($topic, $stranger));
    }

    public function test_topic_is_editable_by_its_author_a_member_and_by_admins(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group, GroupRole::Member);
        $admin = $this->joined($group, GroupRole::Admin);
        $otherMember = $this->joined($group, GroupRole::Member);
        $stranger = Member::factory()->create();
        $topic = CommunityTopic::factory()->create(['community_id' => $group->getKey(), 'member_id' => $author->getKey()]);

        $this->assertTrue(CommunityTopicAccess::canEditTopic($topic, $author));
        $this->assertTrue(CommunityTopicAccess::canEditTopic($topic, $admin));
        $this->assertFalse(CommunityTopicAccess::canEditTopic($topic, $otherMember));
        $this->assertFalse(CommunityTopicAccess::canEditTopic($topic, $stranger));
    }

    public function test_an_author_who_left_the_community_can_no_longer_edit(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group, GroupRole::Member);
        $topic = CommunityTopic::factory()->create(['community_id' => $group->getKey(), 'member_id' => $author->getKey()]);

        GroupMember::query()
            ->where('group_id', $group->getKey())
            ->where('member_id', $author->getKey())
            ->delete();

        $this->assertFalse(CommunityTopicAccess::canEditTopic($topic->fresh(), $author));
    }

    public function test_comment_is_deletable_by_its_author_the_topic_author_and_admins(): void
    {
        $group = Group::factory()->create();
        $topicAuthor = $this->joined($group, GroupRole::Member);
        $admin = $this->joined($group, GroupRole::Admin);
        $commenter = $this->joined($group, GroupRole::Member);
        $otherMember = $this->joined($group, GroupRole::Member);
        $topic = CommunityTopic::factory()->create(['community_id' => $group->getKey(), 'member_id' => $topicAuthor->getKey()]);
        $comment = CommunityTopicComment::factory()->create([
            'community_topic_id' => $topic->getKey(),
            'member_id' => $commenter->getKey(),
        ]);

        $this->assertTrue(CommunityTopicAccess::canDeleteComment($comment, $commenter));
        $this->assertTrue(CommunityTopicAccess::canDeleteComment($comment, $topicAuthor));
        $this->assertTrue(CommunityTopicAccess::canDeleteComment($comment, $admin));
        $this->assertFalse(CommunityTopicAccess::canDeleteComment($comment, $otherMember));
    }

    public function test_a_withdrawn_commenter_cannot_be_impersonated_for_deletion(): void
    {
        $group = Group::factory()->create();
        $topic = CommunityTopic::factory()->create(['community_id' => $group->getKey()]);
        // member_id null = the commenter withdrew; an ordinary member is not its author.
        $comment = CommunityTopicComment::factory()->create([
            'community_topic_id' => $topic->getKey(),
            'member_id' => null,
        ]);
        $member = $this->joined($group, GroupRole::Member);

        $this->assertFalse(CommunityTopicAccess::canDeleteComment($comment->fresh(), $member));
    }
}
