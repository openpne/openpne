<?php

namespace Tests\Feature\Compat;

use App\Features\Group\GroupRole;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupMember;
use App\Models\GroupTopic;
use App\Models\Member;
use Database\Seeders\NavigationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Asserts the OpenPNE 3 `community` word: the stored nav type is `group`, but the presentation
 * token keeps the OpenPNE 3 name so a site's custom CSS keeps matching.
 */
class ClassicGroupLocalNavTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(NavigationSeeder::class);
    }

    public function test_group_page_renders_the_community_localnav(): void
    {
        $group = Group::factory()->create();

        $this->actingAs(Member::factory()->create())
            ->get(route('group.show', $group))
            ->assertOk()
            ->assertSee('<ul class="community">', false)
            // The `<li>` ids carry the same presentation token, keyed off the OpenPNE 3 source_uri.
            ->assertSee('<li id="community__community_home">', false)
            ->assertSee('<li id="community__community_join">', false)
            ->assertSee(route('group.show', $group), false) // Top → /groups/{id}
            ->assertSee(route('group.topics.index', $group), false) // Topics → /groups/{id}/topics
            ->assertSee(route('group.events.index', $group), false) // Events
            ->assertSee(route('group.join.show', ['group' => $group->getKey()]), false); // Join → /groups/{id}/join
    }

    public function test_topic_page_renders_the_community_localnav(): void
    {
        $group = Group::factory()->create();
        $viewer = Member::factory()->create();
        GroupMember::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $viewer->getKey(),
            'role' => GroupRole::Member,
        ]);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey()]);

        foreach ([route('group.topics.index', $group), route('group.topics.show', $topic)] as $url) {
            $this->actingAs($viewer)->get($url)
                ->assertOk()
                ->assertSee('<ul class="community">', false)
                ->assertSee(route('group.show', $group), false);
        }
    }

    /**
     * The topic / event comment-delete confirms keep the community context their parent pages
     * carry (OpenPNE 3 sf_nav_type=community).
     */
    public function test_comment_delete_confirms_keep_the_community_localnav(): void
    {
        $group = Group::factory()->create();
        $admin = Member::factory()->create();
        GroupMember::factory()->admin()->create(['group_id' => $group->getKey(), 'member_id' => $admin->getKey()]);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $admin->getKey()]);
        $topicComment = $topic->comments()->create(['member_id' => $admin->getKey(), 'body' => 'c', 'number' => 1]);
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'member_id' => $admin->getKey()]);
        $eventComment = $event->comments()->create(['member_id' => $admin->getKey(), 'body' => 'c', 'number' => 1]);

        foreach ([
            route('group.topics.comment.delete.show', $topicComment),
            route('group.events.comment.delete.show', $eventComment),
        ] as $url) {
            $this->actingAs($admin)->get($url)
                ->assertOk()
                ->assertSee('<ul class="community">', false)
                ->assertSee(route('group.show', $group), false);
        }
    }

    /**
     * Regression: the classic() helper once read the pre-rename `community` data key, so these
     * three confirms dropped the nav.
     */
    public function test_join_quit_and_delete_confirms_render_the_community_localnav(): void
    {
        $group = Group::factory()->create();
        $outsider = Member::factory()->create();
        $member = Member::factory()->create();
        GroupMember::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
            'role' => GroupRole::Member,
        ]);
        $admin = Member::factory()->create();
        GroupMember::factory()->admin()->create(['group_id' => $group->getKey(), 'member_id' => $admin->getKey()]);

        foreach ([
            [$outsider, route('group.join.show', ['group' => $group->getKey()])],
            [$member, route('group.quit.show', ['group' => $group->getKey()])],
            [$admin, route('group.delete.show', ['group' => $group->getKey()])],
        ] as [$viewer, $url]) {
            $this->actingAs($viewer)->get($url)
                ->assertOk()
                ->assertSee('<ul class="community">', false)
                ->assertSee(route('group.show', $group), false);
        }
    }

    public function test_search_page_keeps_the_default_localnav(): void
    {
        $this->actingAs(Member::factory()->create())
            ->get('/groups')
            ->assertOk()
            ->assertSee('<ul class="default">', false)
            ->assertDontSee('<ul class="community">', false);
    }

    public function test_a_member_page_still_renders_the_friend_localnav(): void
    {
        $viewer = Member::factory()->create();
        $other = Member::factory()->create();

        $this->actingAs($viewer)->get(route('member.profile.show', $other))
            ->assertOk()
            ->assertSee('<ul class="friend">', false)
            ->assertDontSee('<ul class="community">', false);
    }
}
