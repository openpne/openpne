<?php

namespace Tests\Feature\Compat;

use App\Features\Community\CommunityRole;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\CommunityTopic;
use App\Models\Member;
use Database\Seeders\NavigationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OpenPNE 3 community localNav: a page about one community renders the `community` set with that
 * community's id threaded into its Top / Topics / Events / Join / Leave links (OpenPNE 3
 * sf_nav_type=community). The search and member-community-list pages keep the default nav.
 */
class ClassicCommunityLocalNavTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(NavigationSeeder::class);
    }

    public function test_community_page_renders_the_community_localnav(): void
    {
        $community = Community::factory()->create();

        $this->actingAs(Member::factory()->create())
            ->get(route('community.show', $community))
            ->assertOk()
            ->assertSee('<ul class="community">', false)
            ->assertSee(route('community.show', $community), false) // Top → /community/{id}
            ->assertSee(route('communityTopic.index', $community), false) // Topics → /communityTopic/listCommunity/{id}
            ->assertSee(route('communityEvent.index', $community), false) // Events
            ->assertSee(route('community.join.show', ['id' => $community->getKey()]), false); // Join → /community/join?id={id}
    }

    public function test_topic_page_renders_the_community_localnav(): void
    {
        $community = Community::factory()->create();
        $viewer = Member::factory()->create();
        CommunityMember::factory()->create([
            'community_id' => $community->getKey(),
            'member_id' => $viewer->getKey(),
            'role' => CommunityRole::Member,
        ]);
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey()]);

        foreach ([route('communityTopic.index', $community), route('communityTopic.show', $topic)] as $url) {
            $this->actingAs($viewer)->get($url)
                ->assertOk()
                ->assertSee('<ul class="community">', false)
                ->assertSee(route('community.show', $community), false);
        }
    }

    public function test_search_page_keeps_the_default_localnav(): void
    {
        $this->actingAs(Member::factory()->create())
            ->get('/community/search')
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
