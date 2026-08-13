<?php

namespace Tests\Feature\CommunityTopic\Modern;

use App\Features\CommunityTopic\TopicReadAccess;
use App\Features\Group\GroupRole;
use App\Models\CommunityTopic;
use App\Models\CommunityTopicComment;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunityTopicRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['openpne.surface_mode' => 'modern_default']);
    }

    private function joined(Group $group, GroupRole $role = GroupRole::Member): Member
    {
        $member = Member::factory()->create();
        GroupMember::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
            'role' => $role,
        ]);

        return $member;
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $group = Group::factory()->create();
        $topic = CommunityTopic::factory()->create(['community_id' => $group->getKey()]);

        $this->get(route('communityTopic.index', $group))->assertRedirect('/login');
        $this->get(route('communityTopic.new', $group))->assertRedirect('/login');
        $this->get(route('communityTopic.show', $topic))->assertRedirect('/login');
        $this->post(route('communityTopic.store', $group))->assertRedirect('/login');
        $this->get(route('communityTopic.edit', $topic))->assertRedirect('/login');
        $this->post(route('communityTopic.delete', $topic))->assertRedirect('/login');
    }

    public function test_modern_index_renders_the_board(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);
        CommunityTopic::factory()->create(['community_id' => $group->getKey(), 'member_id' => $member->getKey()]);

        $this->actingAs($member)
            ->get(route('communityTopic.index', $group))
            ->assertInertia(fn ($page) => $page
                ->component('community/topic/index')
                ->where('group.id', $group->getKey())
                ->has('topics.data', 1)
                ->has('topics.data.0.author')
                ->where('canPost', true)
            );
    }

    public function test_modern_show_renders_the_topic_with_its_comments(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $topic = CommunityTopic::factory()->create(['community_id' => $group->getKey(), 'member_id' => $author->getKey()]);
        CommunityTopicComment::factory()->create([
            'community_topic_id' => $topic->getKey(),
            'member_id' => $author->getKey(),
            'number' => 1,
        ]);

        $this->actingAs($author)
            ->get(route('communityTopic.show', $topic))
            ->assertInertia(fn ($page) => $page
                ->component('community/topic/show')
                ->where('topic.id', $topic->getKey())
                ->where('thread.total', 1)
                ->has('thread.comments', 1)
                ->where('thread.comments.0.deletable', true)
                ->where('canComment', true)
                ->where('canEdit', true)
            );
    }

    public function test_modern_show_orders_comments_by_id_not_number(): void
    {
        // number is a racy label on migrated data; the thread pager orders by id, so Modern must
        // list comments in insertion order regardless of number (matching Classic).
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $topic = CommunityTopic::factory()->create(['community_id' => $group->getKey(), 'member_id' => $author->getKey()]);
        $first = CommunityTopicComment::factory()->create(['community_topic_id' => $topic->getKey(), 'member_id' => $author->getKey(), 'number' => 3]);
        $second = CommunityTopicComment::factory()->create(['community_topic_id' => $topic->getKey(), 'member_id' => $author->getKey(), 'number' => 1]);
        $third = CommunityTopicComment::factory()->create(['community_topic_id' => $topic->getKey(), 'member_id' => $author->getKey(), 'number' => 2]);

        $this->actingAs($author)
            ->get(route('communityTopic.show', $topic))
            ->assertInertia(fn ($page) => $page
                ->where('thread.comments.0.id', $first->getKey())
                ->where('thread.comments.1.id', $second->getKey())
                ->where('thread.comments.2.id', $third->getKey())
            );
    }

    public function test_modern_show_paginates_the_comment_thread(): void
    {
        // Large threads must not serialize every comment: the pager caps a page at 20.
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $topic = CommunityTopic::factory()->create(['community_id' => $group->getKey(), 'member_id' => $author->getKey()]);
        foreach (range(1, 25) as $n) {
            CommunityTopicComment::factory()->create(['community_topic_id' => $topic->getKey(), 'member_id' => $author->getKey(), 'number' => $n]);
        }

        $this->actingAs($author)
            ->get(route('communityTopic.show', $topic))
            ->assertInertia(fn ($page) => $page
                ->where('thread.total', 25)
                ->where('thread.lastPage', 2)
                ->where('thread.ascending', false)
                ->has('thread.comments', 20)
            );

        $this->actingAs($author)
            ->get(route('communityTopic.show', ['topic' => $topic, 'page' => 2]))
            ->assertInertia(fn ($page) => $page->has('thread.comments', 5));
    }

    public function test_modern_show_returns_404_when_the_board_is_members_only_and_the_viewer_is_a_stranger(): void
    {
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        $topic = CommunityTopic::factory()->create(['community_id' => $group->getKey()]);
        $stranger = Member::factory()->create();

        $this->actingAs($stranger)
            ->get(route('communityTopic.show', $topic))
            ->assertNotFound();
    }

    public function test_modern_new_renders_the_form_for_a_member(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);

        $this->actingAs($member)
            ->get(route('communityTopic.new', $group))
            ->assertInertia(fn ($page) => $page
                ->component('community/topic/edit')
                ->where('group.id', $group->getKey())
                ->where('topic', null)
                ->where('composeEditor', 'rich')
            );
    }

    public function test_modern_new_returns_404_for_a_non_member(): void
    {
        $group = Group::factory()->create();
        $stranger = Member::factory()->create();

        $this->actingAs($stranger)->get(route('communityTopic.new', $group))->assertNotFound();
    }

    public function test_modern_store_creates_a_topic_and_redirects_to_show(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);

        $response = $this->actingAs($member)->post(route('communityTopic.store', $group), [
            'name' => 'Modern Topic',
            'body' => 'Hello board',
        ]);

        $topic = CommunityTopic::where('name', 'Modern Topic')->firstOrFail();
        $response->assertRedirect(route('communityTopic.show', $topic));
        $this->assertDatabaseHas('community_topics', [
            'id' => $topic->getKey(),
            'community_id' => $group->getKey(),
            'member_id' => $member->getKey(),
        ]);
    }

    public function test_modern_edit_renders_the_form_for_the_author(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $topic = CommunityTopic::factory()->create(['community_id' => $group->getKey(), 'member_id' => $author->getKey()]);

        $this->actingAs($author)
            ->get(route('communityTopic.edit', $topic))
            ->assertInertia(fn ($page) => $page
                ->component('community/topic/edit')
                ->where('topic.id', $topic->getKey())
                ->where('group.id', $group->getKey())
                ->where('composeEditor', 'rich')
            );
    }

    public function test_modern_edit_returns_404_for_a_non_editor(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $topic = CommunityTopic::factory()->create(['community_id' => $group->getKey(), 'member_id' => $author->getKey()]);
        $stranger = Member::factory()->create();

        $this->actingAs($stranger)
            ->get(route('communityTopic.edit', $topic))
            ->assertNotFound();
    }

    public function test_modern_update_edits_the_topic_and_redirects_to_show(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $topic = CommunityTopic::factory()->create(['community_id' => $group->getKey(), 'member_id' => $author->getKey()]);

        $this->actingAs($author)
            ->post(route('communityTopic.update', $topic), [
                'name' => 'Renamed',
                'body' => 'Rewritten',
            ])
            ->assertRedirect(route('communityTopic.show', $topic));

        $this->assertDatabaseHas('community_topics', ['id' => $topic->getKey(), 'name' => 'Renamed', 'body' => 'Rewritten']);
    }

    public function test_modern_delete_removes_the_topic_and_redirects_to_the_community(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $topic = CommunityTopic::factory()->create(['community_id' => $group->getKey(), 'member_id' => $author->getKey()]);

        $this->actingAs($author)
            ->post(route('communityTopic.delete', $topic))
            ->assertRedirect(route('group.show', $group));

        $this->assertDatabaseMissing('community_topics', ['id' => $topic->getKey()]);
    }

    public function test_modern_delete_returns_404_for_a_non_editor(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $topic = CommunityTopic::factory()->create(['community_id' => $group->getKey(), 'member_id' => $author->getKey()]);
        $stranger = Member::factory()->create();

        $this->actingAs($stranger)
            ->post(route('communityTopic.delete', $topic))
            ->assertNotFound();
        $this->assertDatabaseHas('community_topics', ['id' => $topic->getKey()]);
    }

    public function test_modern_only_serves_the_canonical_board_as_inertia(): void
    {
        // A modern_only install must not fall through to Classic Blade on the canonical route.
        config()->set('openpne.surface_mode', 'modern_only');
        $group = Group::factory()->create();
        $member = $this->joined($group);

        $this->actingAs($member)
            ->get(route('communityTopic.index', $group))
            ->assertInertia(fn ($page) => $page->component('community/topic/index'));
    }
}
