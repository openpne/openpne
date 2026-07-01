<?php

namespace Tests\Feature\CommunityTopic\Modern;

use App\Features\Community\CommunityRole;
use App\Features\CommunityTopic\TopicReadAccess;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\CommunityTopic;
use App\Models\CommunityTopicComment;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->get(route('communityTopic.modern.index', $community))->assertRedirect('/login');
        $this->get(route('communityTopic.modern.new', $community))->assertRedirect('/login');
        $this->get(route('communityTopic.modern.show', $topic))->assertRedirect('/login');
        $this->post(route('communityTopic.modern.store', $community))->assertRedirect('/login');
        $this->get(route('communityTopic.modern.edit', $topic))->assertRedirect('/login');
        $this->post(route('communityTopic.modern.delete', $topic))->assertRedirect('/login');
    }

    public function test_modern_index_renders_the_board(): void
    {
        $community = Community::factory()->create();
        $member = $this->joined($community);
        CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'member_id' => $member->getKey()]);

        $this->actingAs($member)
            ->get(route('communityTopic.modern.index', $community))
            ->assertInertia(fn ($page) => $page
                ->component('community/topic/index')
                ->where('community.id', $community->getKey())
                ->has('topics.data', 1)
                ->has('topics.data.0.author')
                ->where('canPost', true)
            );
    }

    public function test_modern_show_renders_the_topic_with_its_comments(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);
        CommunityTopicComment::factory()->create([
            'community_topic_id' => $topic->getKey(),
            'member_id' => $author->getKey(),
            'number' => 1,
        ]);

        $this->actingAs($author)
            ->get(route('communityTopic.modern.show', $topic))
            ->assertInertia(fn ($page) => $page
                ->component('community/topic/show')
                ->where('topic.id', $topic->getKey())
                ->has('comments', 1)
                ->where('comments.0.deletable', true)
                ->where('canComment', true)
                ->where('canEdit', true)
            );
    }

    public function test_modern_show_returns_404_when_the_board_is_members_only_and_the_viewer_is_a_stranger(): void
    {
        $community = Community::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey()]);
        $stranger = Member::factory()->create();

        $this->actingAs($stranger)
            ->get(route('communityTopic.modern.show', $topic))
            ->assertNotFound();
    }

    public function test_modern_new_renders_the_form_for_a_member(): void
    {
        $community = Community::factory()->create();
        $member = $this->joined($community);

        $this->actingAs($member)
            ->get(route('communityTopic.modern.new', $community))
            ->assertInertia(fn ($page) => $page
                ->component('community/topic/edit')
                ->where('community.id', $community->getKey())
                ->where('topic', null)
            );
    }

    public function test_modern_new_returns_404_for_a_non_member(): void
    {
        $community = Community::factory()->create();
        $stranger = Member::factory()->create();

        $this->actingAs($stranger)->get(route('communityTopic.modern.new', $community))->assertNotFound();
    }

    public function test_modern_store_creates_a_topic_and_redirects_to_modern_show(): void
    {
        $community = Community::factory()->create();
        $member = $this->joined($community);

        $response = $this->actingAs($member)->post(route('communityTopic.modern.store', $community), [
            'name' => 'Modern Topic',
            'body' => 'Hello board',
        ]);

        $topic = CommunityTopic::where('name', 'Modern Topic')->firstOrFail();
        $response->assertRedirect(route('communityTopic.modern.show', $topic));
        $this->assertDatabaseHas('community_topics', [
            'id' => $topic->getKey(),
            'community_id' => $community->getKey(),
            'member_id' => $member->getKey(),
        ]);
    }

    public function test_modern_edit_renders_the_form_for_the_author(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);

        $this->actingAs($author)
            ->get(route('communityTopic.modern.edit', $topic))
            ->assertInertia(fn ($page) => $page
                ->component('community/topic/edit')
                ->where('topic.id', $topic->getKey())
                ->where('community.id', $community->getKey())
            );
    }

    public function test_modern_edit_returns_404_for_a_non_editor(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);
        $stranger = Member::factory()->create();

        $this->actingAs($stranger)
            ->get(route('communityTopic.modern.edit', $topic))
            ->assertNotFound();
    }

    public function test_modern_update_edits_the_topic_and_redirects_to_modern_show(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);

        $this->actingAs($author)
            ->post(route('communityTopic.modern.update', $topic), [
                'name' => 'Renamed',
                'body' => 'Rewritten',
            ])
            ->assertRedirect(route('communityTopic.modern.show', $topic));

        $this->assertDatabaseHas('community_topics', ['id' => $topic->getKey(), 'name' => 'Renamed', 'body' => 'Rewritten']);
    }

    public function test_modern_delete_removes_the_topic_and_redirects_to_the_modern_community(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);

        $this->actingAs($author)
            ->post(route('communityTopic.modern.delete', $topic))
            ->assertRedirect(route('community.modern.show', $community));

        $this->assertDatabaseMissing('community_topics', ['id' => $topic->getKey()]);
    }

    public function test_modern_delete_returns_404_for_a_non_editor(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);
        $stranger = Member::factory()->create();

        $this->actingAs($stranger)
            ->post(route('communityTopic.modern.delete', $topic))
            ->assertNotFound();
        $this->assertDatabaseHas('community_topics', ['id' => $topic->getKey()]);
    }

    public function test_modern_only_serves_the_canonical_board_as_inertia(): void
    {
        // A modern_only tenant must not fall through to Classic Blade on the canonical route.
        config()->set('openpne.tenant_mode', 'modern_only');
        $community = Community::factory()->create();
        $member = $this->joined($community);

        $this->actingAs($member)
            ->get(route('communityTopic.index', $community))
            ->assertInertia(fn ($page) => $page->component('community/topic/index'));
    }
}
