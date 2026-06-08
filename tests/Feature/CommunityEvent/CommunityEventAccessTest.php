<?php

namespace Tests\Feature\CommunityEvent;

use App\Features\Community\CommunityRole;
use App\Features\CommunityEvent\CommunityEventAccess;
use App\Features\CommunityTopic\TopicPostAuthority;
use App\Features\CommunityTopic\TopicReadAccess;
use App\Models\Community;
use App\Models\CommunityEvent;
use App\Models\CommunityEventComment;
use App\Models\CommunityMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunityEventAccessTest extends TestCase
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

        $this->assertTrue(CommunityEventAccess::canViewBoard($community, $member));
        $this->assertTrue(CommunityEventAccess::canViewBoard($community, $stranger));
    }

    public function test_read_access_members_only_excludes_non_members(): void
    {
        $community = Community::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        $member = $this->joined($community, CommunityRole::Member);
        $stranger = Member::factory()->create();

        $this->assertTrue(CommunityEventAccess::canViewBoard($community, $member));
        $this->assertFalse(CommunityEventAccess::canViewBoard($community, $stranger));
    }

    public function test_post_authority_members_lets_any_member_create(): void
    {
        $community = Community::factory()->create(['topic_post_authority' => TopicPostAuthority::Members]);
        $member = $this->joined($community, CommunityRole::Member);
        $stranger = Member::factory()->create();

        $this->assertTrue(CommunityEventAccess::canPostEvent($community, $member));
        $this->assertFalse(CommunityEventAccess::canPostEvent($community, $stranger));
    }

    public function test_post_authority_admins_only_limits_creating_to_admins(): void
    {
        $community = Community::factory()->create(['topic_post_authority' => TopicPostAuthority::AdminsOnly]);
        $admin = $this->joined($community, CommunityRole::Admin);
        $member = $this->joined($community, CommunityRole::Member);

        $this->assertTrue(CommunityEventAccess::canPostEvent($community, $admin));
        $this->assertFalse(CommunityEventAccess::canPostEvent($community, $member));
    }

    public function test_admins_only_community_still_lets_members_comment_and_participate(): void
    {
        $community = Community::factory()->create(['topic_post_authority' => TopicPostAuthority::AdminsOnly]);
        $admin = $this->joined($community, CommunityRole::Admin);
        $member = $this->joined($community, CommunityRole::Member);
        $stranger = Member::factory()->create();
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey(), 'member_id' => $admin->getKey()]);

        $this->assertTrue(CommunityEventAccess::canComment($event, $member));
        $this->assertTrue(CommunityEventAccess::canParticipate($event, $member));
        $this->assertFalse(CommunityEventAccess::canComment($event, $stranger));
        $this->assertFalse(CommunityEventAccess::canParticipate($event, $stranger));
    }

    public function test_event_is_editable_by_its_author_a_member_and_by_admins(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community, CommunityRole::Member);
        $admin = $this->joined($community, CommunityRole::Admin);
        $otherMember = $this->joined($community, CommunityRole::Member);
        $stranger = Member::factory()->create();
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);

        $this->assertTrue(CommunityEventAccess::canEditEvent($event, $author));
        $this->assertTrue(CommunityEventAccess::canEditEvent($event, $admin));
        $this->assertFalse(CommunityEventAccess::canEditEvent($event, $otherMember));
        $this->assertFalse(CommunityEventAccess::canEditEvent($event, $stranger));
    }

    public function test_an_author_who_left_the_community_can_no_longer_edit(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community, CommunityRole::Member);
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);

        CommunityMember::query()
            ->where('community_id', $community->getKey())
            ->where('member_id', $author->getKey())
            ->delete();

        $this->assertFalse(CommunityEventAccess::canEditEvent($event->fresh(), $author));
    }

    public function test_comment_is_deletable_by_its_author_the_event_author_and_admins(): void
    {
        $community = Community::factory()->create();
        $eventAuthor = $this->joined($community, CommunityRole::Member);
        $admin = $this->joined($community, CommunityRole::Admin);
        $commenter = $this->joined($community, CommunityRole::Member);
        $otherMember = $this->joined($community, CommunityRole::Member);
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey(), 'member_id' => $eventAuthor->getKey()]);
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
        $community = Community::factory()->create();
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey()]);
        // member_id null = the commenter withdrew; an ordinary member is not its author.
        $comment = CommunityEventComment::factory()->create([
            'community_event_id' => $event->getKey(),
            'member_id' => null,
        ]);
        $member = $this->joined($community, CommunityRole::Member);

        $this->assertFalse(CommunityEventAccess::canDeleteComment($comment->fresh(), $member));
    }
}
