<?php

namespace Tests\Feature\GroupTopic\Classic;

use App\Features\Group\GroupRole;
use App\Features\GroupTopic\TopicPostAuthority;
use App\Features\GroupTopic\TopicReadAccess;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GroupTopicRoutesTest extends TestCase
{
    use RefreshDatabase;

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
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey()]);

        $this->get(route('group.topics.index', $group))->assertRedirect('/login');
        $this->get(route('group.topics.show', $topic))->assertRedirect('/login');
        $this->post(route('group.topics.store', $group))->assertRedirect('/login');
    }

    public function test_board_renders_with_body_id_and_most_recent_activity_first(): void
    {
        $group = Group::factory()->create();
        $stale = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'name' => 'Stale thread']);
        $fresh = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'name' => 'Fresh thread']);
        DB::table('group_topics')->where('id', $stale->getKey())->update(['updated_at' => now()->subDays(3)]);

        $response = $this->actingAs($this->joined($group))->get(route('group.topics.index', $group));

        $response->assertOk();
        $response->assertSee('id="page_communityTopic_listCommunity"', false);
        $response->assertSeeInOrder(['Fresh thread', 'Stale thread']);
    }

    public function test_board_shows_comment_counts(): void
    {
        $group = Group::factory()->create();
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'name' => 'Counted']);
        GroupTopicComment::factory()->count(2)->sequence(['number' => 1], ['number' => 2])
            ->create(['group_topic_id' => $topic->getKey()]);

        $response = $this->actingAs($this->joined($group))->get(route('group.topics.index', $group));

        $response->assertOk();
        // listCommunitySuccess.php formats the label as sprintf('%s(%d)') — no space before the count.
        $response->assertSee('Counted(2)');
    }

    public function test_board_draws_the_openpne3_recent_list(): void
    {
        $group = Group::factory()->create();
        $author = Member::factory()->create(['name' => 'Tess']);
        $topic = GroupTopic::factory()->create([
            'group_id' => $group->getKey(), 'name' => 'A thread', 'member_id' => $author->getKey(),
        ]);
        DB::table('group_topics')->where('id', $topic->getKey())->update(['updated_at' => '2026-06-04 13:44:00']);

        $response = $this->actingAs($this->joined($group))
            ->withSession(['locale' => 'ja'])
            ->get(route('group.topics.index', $group))
            ->assertOk();

        // One dl per topic: the last-activity datetime in the dt, the "name(count)" link in the dd.
        $response->assertSee('<dt>2026年06月04日 13:44</dt>', false);
        $response->assertSee('<dd><a href="'.route('group.topics.show', $topic).'">A thread(0)</a> (Tess)</dd>', false);
        // The pager brackets the list, as op_include_pager_navigation does above and below it.
        $this->assertSame(2, substr_count((string) $response->getContent(), 'class="pagerRelative"'));
    }

    public function test_members_only_board_is_hidden_from_non_members(): void
    {
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey()]);
        $stranger = Member::factory()->create();

        $this->actingAs($stranger)->get(route('group.topics.index', $group))->assertNotFound();
        $this->actingAs($stranger)->get(route('group.topics.show', $topic))->assertNotFound();

        // A member of the same community may read it.
        $this->actingAs($this->joined($group))->get(route('group.topics.show', $topic))->assertOk();
    }

    public function test_everyone_board_is_visible_to_any_signed_in_member(): void
    {
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::Everyone]);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey()]);

        $this->actingAs(Member::factory()->create())->get(route('group.topics.show', $topic))->assertOk();
    }

    public function test_show_renders_topic_with_body_id(): void
    {
        $group = Group::factory()->create();
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'name' => 'Hello board', 'body' => 'First post.']);

        $response = $this->actingAs($this->joined($group))->get(route('group.topics.show', $topic));

        $response->assertOk();
        $response->assertSee('id="page_communityTopic_show"', false);
        $response->assertSee('Hello board');
        $response->assertSee('First post.');
    }

    public function test_show_for_unknown_topic_returns_404(): void
    {
        $this->actingAs(Member::factory()->create())->get('/topics/999999')->assertNotFound();
    }

    public function test_new_topic_is_admin_only_when_posting_is_restricted(): void
    {
        $group = Group::factory()->create(['topic_post_authority' => TopicPostAuthority::AdminsOnly]);
        $member = $this->joined($group, GroupRole::Member);
        $admin = $this->joined($group, GroupRole::Admin);

        $this->actingAs($member)->get(route('group.topics.new', $group))->assertNotFound();
        $this->actingAs($member)->post(route('group.topics.store', $group), ['name' => 'No', 'body' => 'Nope'])->assertNotFound();

        $this->actingAs($admin)->get(route('group.topics.new', $group))
            ->assertOk()
            ->assertSee('id="page_communityTopic_new"', false);
    }

    public function test_a_member_posts_a_topic_and_is_redirected_to_it(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);

        $response = $this->actingAs($member)->post(route('group.topics.store', $group), [
            'name' => 'Welcome',
            'body' => 'Say hi here.',
        ]);

        $topic = GroupTopic::where('name', 'Welcome')->firstOrFail();
        $response->assertRedirect(route('group.topics.show', $topic));
        $this->assertSame($member->getKey(), $topic->member_id);
        $this->assertSame($group->getKey(), $topic->group_id);
    }

    public function test_an_unauthorized_poster_gets_404_even_with_an_invalid_payload(): void
    {
        // Posting authority is gated before validation, so a non-member's empty payload returns the
        // same 404 as a valid one rather than leaking the board through a validation error.
        $group = Group::factory()->create();
        $stranger = Member::factory()->create();

        $this->actingAs($stranger)->post(route('group.topics.store', $group), ['name' => '', 'body' => ''])
            ->assertNotFound();
        $this->assertDatabaseCount('group_topics', 0);
    }

    public function test_editing_a_topic_is_limited_to_its_author_and_admins(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $admin = $this->joined($group, GroupRole::Admin);
        $other = $this->joined($group);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);

        $this->actingAs($other)->get(route('group.topics.edit', $topic))->assertNotFound();
        $this->actingAs($admin)->get(route('group.topics.edit', $topic))->assertOk()
            ->assertSee('id="page_communityTopic_edit"', false);

        $response = $this->actingAs($author)->post(route('group.topics.update', $topic), [
            'name' => 'Edited title',
            'body' => $topic->body,
        ]);
        $response->assertRedirect(route('group.topics.show', $topic));
        $this->assertSame('Edited title', $topic->fresh()->name);
    }

    public function test_a_non_editor_gets_404_on_update_even_with_an_invalid_payload(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $other = $this->joined($group);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);

        // Edit authority is gated before validation; a non-editor's empty payload 404s like a valid one.
        $this->actingAs($other)->post(route('group.topics.update', $topic), ['name' => '', 'body' => ''])
            ->assertNotFound();
        $this->assertSame($topic->name, $topic->fresh()->name);
    }

    public function test_deleting_a_topic_is_limited_to_author_and_admins_and_returns_to_the_community(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $other = $this->joined($group);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);

        $this->actingAs($other)->get(route('group.topics.delete.show', $topic))->assertNotFound();
        $this->actingAs($other)->post(route('group.topics.delete', $topic))->assertNotFound();

        $this->actingAs($author)->get(route('group.topics.delete.show', $topic))
            ->assertOk()
            ->assertSee('id="page_communityTopic_deleteConfirm"', false);

        $this->actingAs($author)->post(route('group.topics.delete', $topic))
            ->assertRedirect(route('group.show', $group));
        $this->assertDatabaseMissing('group_topics', ['id' => $topic->getKey()]);
    }

    public function test_community_home_shows_the_recent_topics_box_for_board_readers(): void
    {
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        GroupTopic::factory()->create(['group_id' => $group->getKey(), 'name' => 'Box thread']);

        // A member sees the box and the board link.
        $this->actingAs($this->joined($group))->get(route('group.show', $group))
            ->assertOk()
            ->assertSee('Box thread')
            ->assertSee(route('group.topics.index', $group), false);

        // A non-member of a members-only board does not see the box.
        $this->actingAs(Member::factory()->create())->get(route('group.show', $group))
            ->assertOk()
            ->assertDontSee('Box thread');
    }
}
