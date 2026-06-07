<?php

namespace Tests\Feature\CommunityTopic;

use App\Features\Community\CommunityRole;
use App\Features\CommunityTopic\CommunityTopicAccess;
use App\Features\CommunityTopic\TopicPostAuthority;
use App\Features\CommunityTopic\TopicReadAccess;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\CommunityTopic;
use App\Models\CommunityTopicComment;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunityTopicAccessTest extends TestCase
{
    use RefreshDatabase;

    private function joined(Community $community, CommunityRole $role): Member
    {
        $member = Member::factory()->create();
        CommunityMember::factory()->create([
            'community_id' => $community->getKey(),
            'member_id' => $member->getKey(),
            'role' => $role,
        ]);

        return $member;
    }

    public function test_read_access_everyone_admits_any_signed_in_member(): void
    {
        $community = Community::factory()->create(['topic_read_access' => TopicReadAccess::Everyone]);
        $member = $this->joined($community, CommunityRole::Member);
        $stranger = Member::factory()->create();

        $this->assertTrue(CommunityTopicAccess::canViewBoard($community, $member));
        $this->assertTrue(CommunityTopicAccess::canViewBoard($community, $stranger));
    }

    public function test_read_access_members_only_excludes_non_members(): void
    {
        $community = Community::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        $member = $this->joined($community, CommunityRole::Member);
        $stranger = Member::factory()->create();

        $this->assertTrue(CommunityTopicAccess::canViewBoard($community, $member));
        $this->assertFalse(CommunityTopicAccess::canViewBoard($community, $stranger));
    }

    public function test_post_authority_members_lets_any_member_post(): void
    {
        $community = Community::factory()->create(['topic_post_authority' => TopicPostAuthority::Members]);
        $member = $this->joined($community, CommunityRole::Member);
        $stranger = Member::factory()->create();

        $this->assertTrue(CommunityTopicAccess::canPostTopic($community, $member));
        $this->assertFalse(CommunityTopicAccess::canPostTopic($community, $stranger));
    }

    public function test_post_authority_admins_only_limits_posting_to_admins(): void
    {
        $community = Community::factory()->create(['topic_post_authority' => TopicPostAuthority::AdminsOnly]);
        $admin = $this->joined($community, CommunityRole::Admin);
        $member = $this->joined($community, CommunityRole::Member);

        $this->assertTrue(CommunityTopicAccess::canPostTopic($community, $admin));
        $this->assertFalse(CommunityTopicAccess::canPostTopic($community, $member));
    }

    public function test_admins_only_board_still_lets_members_comment(): void
    {
        $community = Community::factory()->create(['topic_post_authority' => TopicPostAuthority::AdminsOnly]);
        $admin = $this->joined($community, CommunityRole::Admin);
        $member = $this->joined($community, CommunityRole::Member);
        $stranger = Member::factory()->create();
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'member_id' => $admin->getKey()]);

        $this->assertTrue(CommunityTopicAccess::canComment($topic, $member));
        $this->assertFalse(CommunityTopicAccess::canComment($topic, $stranger));
    }

    public function test_topic_is_editable_by_its_author_a_member_and_by_admins(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community, CommunityRole::Member);
        $admin = $this->joined($community, CommunityRole::Admin);
        $otherMember = $this->joined($community, CommunityRole::Member);
        $stranger = Member::factory()->create();
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);

        $this->assertTrue(CommunityTopicAccess::canEditTopic($topic, $author));
        $this->assertTrue(CommunityTopicAccess::canEditTopic($topic, $admin));
        $this->assertFalse(CommunityTopicAccess::canEditTopic($topic, $otherMember));
        $this->assertFalse(CommunityTopicAccess::canEditTopic($topic, $stranger));
    }

    public function test_an_author_who_left_the_community_can_no_longer_edit(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community, CommunityRole::Member);
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);

        CommunityMember::query()
            ->where('community_id', $community->getKey())
            ->where('member_id', $author->getKey())
            ->delete();

        $this->assertFalse(CommunityTopicAccess::canEditTopic($topic->fresh(), $author));
    }

    public function test_comment_is_deletable_by_its_author_the_topic_author_and_admins(): void
    {
        $community = Community::factory()->create();
        $topicAuthor = $this->joined($community, CommunityRole::Member);
        $admin = $this->joined($community, CommunityRole::Admin);
        $commenter = $this->joined($community, CommunityRole::Member);
        $otherMember = $this->joined($community, CommunityRole::Member);
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'member_id' => $topicAuthor->getKey()]);
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
        $community = Community::factory()->create();
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey()]);
        // member_id null = the commenter withdrew; an ordinary member is not its author.
        $comment = CommunityTopicComment::factory()->create([
            'community_topic_id' => $topic->getKey(),
            'member_id' => null,
        ]);
        $member = $this->joined($community, CommunityRole::Member);

        $this->assertFalse(CommunityTopicAccess::canDeleteComment($comment->fresh(), $member));
    }
}
