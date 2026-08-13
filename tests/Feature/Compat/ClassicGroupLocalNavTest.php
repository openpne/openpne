<?php

namespace Tests\Feature\Compat;

use App\Features\Group\GroupRole;
use App\Models\CommunityEvent;
use App\Models\CommunityTopic;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use Database\Seeders\NavigationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OpenPNE 3 community localNav: a page about one group renders the `group` set with that group's
 * id threaded into its Top / Topics / Events / Join / Leave links (OpenPNE 3 sf_nav_type=community).
 * The search and member-group-list pages keep the default nav.
 *
 * The markup keeps OpenPNE 3's `community` word — the stored type is `group`, the presentation
 * token is not (Navigation::presentationToken), so a site's custom CSS keeps matching.
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
            ->assertSee(route('communityTopic.index', $group), false) // Topics → /communityTopic/listCommunity/{id}
            ->assertSee(route('communityEvent.index', $group), false) // Events
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
        $topic = CommunityTopic::factory()->create(['community_id' => $group->getKey()]);

        foreach ([route('communityTopic.index', $group), route('communityTopic.show', $topic)] as $url) {
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
        $topic = CommunityTopic::factory()->create(['community_id' => $group->getKey(), 'member_id' => $admin->getKey()]);
        $topicComment = $topic->comments()->create(['member_id' => $admin->getKey(), 'body' => 'c', 'number' => 1]);
        $event = CommunityEvent::factory()->create(['community_id' => $group->getKey(), 'member_id' => $admin->getKey()]);
        $eventComment = $event->comments()->create(['member_id' => $admin->getKey(), 'body' => 'c', 'number' => 1]);

        foreach ([
            route('communityTopic.comment.delete.show', $topicComment),
            route('communityEvent.comment.delete.show', $eventComment),
        ] as $url) {
            $this->actingAs($admin)->get($url)
                ->assertOk()
                ->assertSee('<ul class="community">', false)
                ->assertSee(route('group.show', $group), false);
        }
    }

    /**
     * The join / quit / delete confirms are pages about one concrete group too. Regression: the
     * classic() helper read the pre-rename `community` data key, so these three dropped the nav.
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
