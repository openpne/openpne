<?php

namespace Tests\Feature\Timeline;

use App\Features\Community\CommunityRole;
use App\Features\CommunityTopic\TopicReadAccess;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The community timeline's pages. OpenPNE 3 served this at /community/:id/timeline (its named
 * community_timeline route), so the URL is unchanged rather than redirected.
 */
class CommunityTimelinePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_member_reads_the_communitys_timeline(): void
    {
        $community = Community::factory()->create();
        $member = $this->joined($community);
        $post = TimelinePost::factory()->inCommunity($community)->create(['member_id' => $member->getKey()]);

        $this->actingAs($member)->get("/community/{$community->getKey()}/timeline")
            ->assertOk()
            ->assertSee($post->body);
    }

    public function test_a_members_only_community_hides_its_timeline_from_outsiders(): void
    {
        $community = Community::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        $this->joined($community);

        $this->actingAs(Member::factory()->create())
            ->get("/community/{$community->getKey()}/timeline")
            ->assertNotFound();
    }

    public function test_an_everyone_community_is_readable_but_not_writable_by_outsiders(): void
    {
        $community = Community::factory()->create(['topic_read_access' => TopicReadAccess::Everyone]);
        $outsider = Member::factory()->create();

        $this->actingAs($outsider)->get("/community/{$community->getKey()}/timeline")->assertOk();
        $this->actingAs($outsider)->get("/community/{$community->getKey()}/timeline/new")->assertNotFound();
        $this->actingAs($outsider)
            ->post("/community/{$community->getKey()}/timeline", ['body' => 'let me in'])
            ->assertNotFound();
    }

    public function test_a_member_posts_from_the_page_and_lands_back_on_it(): void
    {
        $community = Community::factory()->create();
        $member = $this->joined($community);

        $this->actingAs($member)
            ->post("/community/{$community->getKey()}/timeline", ['body' => 'hello community'])
            ->assertRedirect(route('community.timeline', ['community' => $community->getKey()]));

        $this->assertDatabaseHas('timeline_posts', [
            'community_id' => $community->getKey(),
            'body' => 'hello community',
        ]);
    }

    public function test_the_openpne3_fallback_url_redirects_to_the_page(): void
    {
        $community = Community::factory()->create();

        $this->actingAs($this->joined($community))
            ->get("/timeline/community/id/{$community->getKey()}")
            ->assertRedirect(route('community.timeline', ['community' => $community->getKey()]));
    }

    public function test_either_unit_switched_off_closes_the_page(): void
    {
        $community = Community::factory()->create();
        $member = $this->joined($community);

        $this->setSnsSetting(Feature::Timeline->settingKey(), false);
        $this->freshRequestState();
        $this->actingAs($member)->get("/community/{$community->getKey()}/timeline")->assertNotFound();
    }

    public function test_mention_candidates_for_a_community_are_refused_to_outsiders(): void
    {
        // The candidate list is the community's roster, one name at a time.
        $community = Community::factory()->create(['topic_read_access' => TopicReadAccess::Everyone]);
        $this->joined($community);

        $this->actingAs(Member::factory()->create())
            ->getJson("/timeline/mention-candidates?q=&community={$community->getKey()}")
            ->assertNotFound();

        $this->actingAs($this->joined($community))
            ->getJson("/timeline/mention-candidates?q=&community={$community->getKey()}")
            ->assertOk();
    }

    public function test_deleting_a_community_post_returns_to_the_community_timeline(): void
    {
        $community = Community::factory()->create();
        $member = $this->joined($community);
        $post = TimelinePost::factory()->inCommunity($community)->create(['member_id' => $member->getKey()]);

        $this->actingAs($member)->post("/timeline/delete/{$post->getKey()}")
            ->assertRedirect(route('community.timeline', ['community' => $community->getKey()]));
    }

    public function test_the_community_home_shows_the_box_to_members_only(): void
    {
        $community = Community::factory()->create(['topic_read_access' => TopicReadAccess::Everyone]);
        $member = $this->joined($community);
        $post = TimelinePost::factory()->inCommunity($community)->create(['member_id' => $member->getKey()]);

        // OpenPNE 3 gated the injected box on membership (isPrivilegeBelong): it leads with a
        // compose form, which a non-member cannot use.
        $this->actingAs($member)->get("/community/{$community->getKey()}")
            ->assertOk()
            ->assertSee($post->body);

        $this->actingAs(Member::factory()->create())->get("/community/{$community->getKey()}")
            ->assertOk()
            ->assertDontSee($post->body);
    }

    private function joined(Community $community): Member
    {
        $member = Member::factory()->create();
        CommunityMember::factory()->create([
            'community_id' => $community->getKey(),
            'member_id' => $member->getKey(),
            'role' => CommunityRole::Member,
        ]);

        return $member;
    }
}
