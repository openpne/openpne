<?php

namespace Tests\Feature\CommunityTopic\Classic;

use App\Features\Community\CommunityRole;
use App\Features\CommunityTopic\TopicPostAuthority;
use App\Features\CommunityTopic\TopicReadAccess;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\CommunityTopic;
use App\Models\CommunityTopicComment;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CommunityTopicRoutesTest extends TestCase
{
    use RefreshDatabase;

    private function joined(Community $community, CommunityRole $role = CommunityRole::Member): Member
    {
        $member = Member::factory()->create();
        CommunityMember::factory()->create([
            'community_id' => $community->getKey(),
            'member_id' => $member->getKey(),
            'role' => $role,
        ]);

        return $member;
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $community = Community::factory()->create();
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey()]);

        $this->get(route('communityTopic.index', $community))->assertRedirect('/login');
        $this->get(route('communityTopic.show', $topic))->assertRedirect('/login');
        $this->post(route('communityTopic.store', $community))->assertRedirect('/login');
    }

    public function test_board_renders_with_body_id_and_most_recent_activity_first(): void
    {
        $community = Community::factory()->create();
        $stale = CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'name' => 'Stale thread']);
        $fresh = CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'name' => 'Fresh thread']);
        DB::table('community_topics')->where('id', $stale->getKey())->update(['updated_at' => now()->subDays(3)]);

        $response = $this->actingAs($this->joined($community))->get(route('communityTopic.index', $community));

        $response->assertOk();
        $response->assertSee('id="page_communityTopic_listCommunity"', false);
        $response->assertSeeInOrder(['Fresh thread', 'Stale thread']);
    }

    public function test_board_shows_comment_counts(): void
    {
        $community = Community::factory()->create();
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'name' => 'Counted']);
        CommunityTopicComment::factory()->count(2)->sequence(['number' => 1], ['number' => 2])
            ->create(['community_topic_id' => $topic->getKey()]);

        $response = $this->actingAs($this->joined($community))->get(route('communityTopic.index', $community));

        $response->assertOk();
        $response->assertSee('Counted (2)');
    }

    public function test_members_only_board_is_hidden_from_non_members(): void
    {
        $community = Community::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey()]);
        $stranger = Member::factory()->create();

        $this->actingAs($stranger)->get(route('communityTopic.index', $community))->assertNotFound();
        $this->actingAs($stranger)->get(route('communityTopic.show', $topic))->assertNotFound();

        // A member of the same community may read it.
        $this->actingAs($this->joined($community))->get(route('communityTopic.show', $topic))->assertOk();
    }

    public function test_everyone_board_is_visible_to_any_signed_in_member(): void
    {
        $community = Community::factory()->create(['topic_read_access' => TopicReadAccess::Everyone]);
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey()]);

        $this->actingAs(Member::factory()->create())->get(route('communityTopic.show', $topic))->assertOk();
    }

    public function test_show_renders_topic_with_body_id(): void
    {
        $community = Community::factory()->create();
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'name' => 'Hello board', 'body' => 'First post.']);

        $response = $this->actingAs($this->joined($community))->get(route('communityTopic.show', $topic));

        $response->assertOk();
        $response->assertSee('id="page_communityTopic_show"', false);
        $response->assertSee('Hello board');
        $response->assertSee('First post.');
    }

    public function test_show_for_unknown_topic_returns_404(): void
    {
        $this->actingAs(Member::factory()->create())->get('/communityTopic/999999')->assertNotFound();
    }

    public function test_new_topic_is_admin_only_when_posting_is_restricted(): void
    {
        $community = Community::factory()->create(['topic_post_authority' => TopicPostAuthority::AdminsOnly]);
        $member = $this->joined($community, CommunityRole::Member);
        $admin = $this->joined($community, CommunityRole::Admin);

        $this->actingAs($member)->get(route('communityTopic.new', $community))->assertNotFound();
        $this->actingAs($member)->post(route('communityTopic.store', $community), ['name' => 'No', 'body' => 'Nope'])->assertNotFound();

        $this->actingAs($admin)->get(route('communityTopic.new', $community))
            ->assertOk()
            ->assertSee('id="page_communityTopic_new"', false);
    }

    public function test_a_member_posts_a_topic_and_is_redirected_to_it(): void
    {
        $community = Community::factory()->create();
        $member = $this->joined($community);

        $response = $this->actingAs($member)->post(route('communityTopic.store', $community), [
            'name' => 'Welcome',
            'body' => 'Say hi here.',
        ]);

        $topic = CommunityTopic::where('name', 'Welcome')->firstOrFail();
        $response->assertRedirect(route('communityTopic.show', $topic));
        $this->assertSame($member->getKey(), $topic->member_id);
        $this->assertSame($community->getKey(), $topic->community_id);
    }

    public function test_editing_a_topic_is_limited_to_its_author_and_admins(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $admin = $this->joined($community, CommunityRole::Admin);
        $other = $this->joined($community);
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);

        $this->actingAs($other)->get(route('communityTopic.edit', $topic))->assertNotFound();
        $this->actingAs($admin)->get(route('communityTopic.edit', $topic))->assertOk()
            ->assertSee('id="page_communityTopic_edit"', false);

        $response = $this->actingAs($author)->post(route('communityTopic.update', $topic), [
            'name' => 'Edited title',
            'body' => $topic->body,
        ]);
        $response->assertRedirect(route('communityTopic.show', $topic));
        $this->assertSame('Edited title', $topic->fresh()->name);
    }

    public function test_deleting_a_topic_is_limited_to_author_and_admins_and_returns_to_the_community(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $other = $this->joined($community);
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);

        $this->actingAs($other)->get(route('communityTopic.delete.show', $topic))->assertNotFound();
        $this->actingAs($other)->post(route('communityTopic.delete', $topic))->assertNotFound();

        $this->actingAs($author)->get(route('communityTopic.delete.show', $topic))
            ->assertOk()
            ->assertSee('id="page_communityTopic_deleteConfirm"', false);

        $this->actingAs($author)->post(route('communityTopic.delete', $topic))
            ->assertRedirect(route('community.show', $community));
        $this->assertDatabaseMissing('community_topics', ['id' => $topic->getKey()]);
    }

    public function test_community_home_shows_the_recent_topics_box_for_board_readers(): void
    {
        $community = Community::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'name' => 'Box thread']);

        // A member sees the box and the board link.
        $this->actingAs($this->joined($community))->get(route('community.show', $community))
            ->assertOk()
            ->assertSee('Box thread')
            ->assertSee(route('communityTopic.index', $community), false);

        // A non-member of a members-only board does not see the box.
        $this->actingAs(Member::factory()->create())->get(route('community.show', $community))
            ->assertOk()
            ->assertDontSee('Box thread');
    }
}
