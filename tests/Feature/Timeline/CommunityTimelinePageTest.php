<?php

namespace Tests\Feature\Timeline;

use App\Features\CommunityTopic\TopicReadAccess;
use App\Features\Group\GroupRole;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The community timeline's pages. OpenPNE 3 served this at /community/:id/timeline, now redirected to the canonical (its named
 * community_timeline route), so the URL is unchanged rather than redirected.
 */
class CommunityTimelinePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_member_reads_the_communitys_timeline(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);
        $post = TimelinePost::factory()->inGroup($group)->create(['member_id' => $member->getKey()]);

        $this->actingAs($member)->get("/groups/{$group->getKey()}/timeline")
            ->assertOk()
            ->assertSee($post->body);
    }

    public function test_a_members_only_community_hides_its_timeline_from_outsiders(): void
    {
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        $this->joined($group);

        $this->actingAs(Member::factory()->create())
            ->get("/groups/{$group->getKey()}/timeline")
            ->assertNotFound();
    }

    public function test_an_everyone_community_is_readable_but_not_writable_by_outsiders(): void
    {
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::Everyone]);
        $outsider = Member::factory()->create();

        $this->actingAs($outsider)->get("/groups/{$group->getKey()}/timeline")->assertOk();
        $this->actingAs($outsider)->get("/groups/{$group->getKey()}/timeline/new")->assertNotFound();
        $this->actingAs($outsider)
            ->post("/groups/{$group->getKey()}/timeline", ['body' => 'let me in'])
            ->assertNotFound();
    }

    public function test_a_member_posts_from_the_page_and_lands_back_on_it(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);

        $this->actingAs($member)
            ->post("/groups/{$group->getKey()}/timeline", ['body' => 'hello community'])
            ->assertRedirect(route('group.timeline', ['group' => $group->getKey()]));

        $this->assertDatabaseHas('timeline_posts', [
            'community_id' => $group->getKey(),
            'body' => 'hello community',
        ]);
    }

    public function test_the_openpne3_fallback_url_redirects_to_the_page(): void
    {
        $group = Group::factory()->create();

        $this->actingAs($this->joined($group))
            ->get("/timeline/community/id/{$group->getKey()}")
            ->assertRedirect(route('group.timeline', ['group' => $group->getKey()]));
    }

    public function test_either_unit_switched_off_closes_the_page(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);

        $this->setSnsSetting(Feature::Timeline->settingKey(), false);
        $this->freshRequestState();
        $this->actingAs($member)->get("/groups/{$group->getKey()}/timeline")->assertNotFound();
    }

    public function test_mention_candidates_for_a_community_are_refused_to_outsiders(): void
    {
        // The candidate list is the community's roster, one name at a time.
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::Everyone]);
        $this->joined($group);

        $this->actingAs(Member::factory()->create())
            ->getJson("/timeline/mention-candidates?q=&community={$group->getKey()}")
            ->assertNotFound();

        $this->actingAs($this->joined($group))
            ->getJson("/timeline/mention-candidates?q=&community={$group->getKey()}")
            ->assertOk();
    }

    public function test_deleting_a_community_post_returns_to_the_community_timeline(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);
        $post = TimelinePost::factory()->inGroup($group)->create(['member_id' => $member->getKey()]);

        $this->actingAs($member)->post("/timeline/delete/{$post->getKey()}")
            ->assertRedirect(route('group.timeline', ['group' => $group->getKey()]));
    }

    public function test_the_community_home_shows_the_box_to_members_only(): void
    {
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::Everyone]);
        $member = $this->joined($group);
        $post = TimelinePost::factory()->inGroup($group)->create(['member_id' => $member->getKey()]);

        // OpenPNE 3 gated the injected box on membership (isPrivilegeBelong): it leads with a
        // compose form, which a non-member cannot use.
        $this->actingAs($member)->get("/groups/{$group->getKey()}")
            ->assertOk()
            ->assertSee($post->body);

        $this->actingAs(Member::factory()->create())->get("/groups/{$group->getKey()}")
            ->assertOk()
            ->assertDontSee($post->body);
    }

    public function test_the_modern_community_home_carries_the_box_for_members_only(): void
    {
        config(['openpne.surface_mode' => 'modern_only']);
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::Everyone]);
        $member = $this->joined($group);
        $post = TimelinePost::factory()->inGroup($group)->create(['member_id' => $member->getKey()]);

        $this->actingAs($member)->get("/groups/{$group->getKey()}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('timelinePosts', 1)
                ->where('timelinePosts.0.id', $post->getKey()));

        $this->actingAs(Member::factory()->create())->get("/groups/{$group->getKey()}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('timelinePosts', null));
    }

    public function test_the_timeline_unit_takes_the_modern_box_away(): void
    {
        config(['openpne.surface_mode' => 'modern_only']);
        $group = Group::factory()->create();
        $member = $this->joined($group);

        $this->setSnsSetting(Feature::Timeline->settingKey(), false);
        $this->freshRequestState();

        $this->actingAs($member)->get("/groups/{$group->getKey()}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('timelinePosts', null));
    }

    // Replying from a community thread ---------------------------------------

    public function test_an_outsider_cannot_reply_on_an_everyone_communitys_thread(): void
    {
        // The reply route is the SNS-wide one, so without a gate here the action's refusal surfaces
        // as a 500 rather than a 404.
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::Everyone]);
        $post = TimelinePost::factory()->inGroup($group)
            ->create(['member_id' => $this->joined($group)->getKey()]);

        $this->actingAs(Member::factory()->create())
            ->post("/timeline/{$post->getKey()}/reply", ['body' => 'let me in'])
            ->assertNotFound();

        $this->assertDatabaseCount('timeline_posts', 1);
    }

    public function test_the_reply_form_is_absent_for_someone_who_may_not_reply(): void
    {
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::Everyone]);
        $member = $this->joined($group);
        $post = TimelinePost::factory()->inGroup($group)->create(['member_id' => $member->getKey()]);

        $this->actingAs($member)->get("/timeline/{$post->getKey()}")
            ->assertOk()->assertSee('timeline-reply-form', false);

        $this->actingAs(Member::factory()->create())->get("/timeline/{$post->getKey()}")
            ->assertOk()->assertDontSee('timeline-reply-form', false);
    }

    public function test_the_reply_forms_mention_picker_is_scoped_to_the_community(): void
    {
        // Offering an outsider here would be a name the submit then silently drops.
        $group = Group::factory()->create();
        $member = $this->joined($group);
        $post = TimelinePost::factory()->inGroup($group)->create(['member_id' => $member->getKey()]);

        $this->actingAs($member)->get("/timeline/{$post->getKey()}")
            ->assertOk()
            ->assertSee('mention-candidates?community='.$group->getKey(), false);
    }

    public function test_the_community_unit_closes_the_mention_roster(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);

        $this->setSnsSetting(Feature::Group->settingKey(), false);
        $this->freshRequestState();

        $this->actingAs($member)
            ->getJson("/timeline/mention-candidates?q=&community={$group->getKey()}")
            ->assertNotFound();
    }

    public function test_a_failed_community_post_returns_to_the_form_it_came_from(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);

        $this->actingAs($member)->post("/groups/{$group->getKey()}/timeline", ['body' => ''])
            ->assertRedirect(route('group.timeline', ['group' => $group->getKey()]));

        $this->actingAs($member)->post("/groups/{$group->getKey()}/timeline", ['body' => '', 'from' => 'new'])
            ->assertRedirect(route('group.timeline.new', ['group' => $group->getKey()]));
    }

    public function test_the_classic_compose_fallback_reaches_a_usable_form(): void
    {
        // The inline box ships hidden; without a real page behind the link, a reader with no script
        // could not post at all.
        $group = Group::factory()->create();
        $member = $this->joined($group);

        $this->actingAs($member)->get("/groups/{$group->getKey()}/timeline")
            ->assertOk()
            ->assertSee(route('group.timeline.new', ['group' => $group->getKey()]), false);

        $this->actingAs($member)->get("/groups/{$group->getKey()}/timeline/new")
            ->assertOk()
            ->assertSee('name="body"', false);
    }

    public function test_the_page_costs_the_same_queries_whatever_the_row_count(): void
    {
        // The reply link's answer is the same for every row — one community, one viewer — so it is
        // decided once by the caller. Deciding it in the row partial instead cost two queries a row
        // (the community relation, then the membership lookup).
        $group = Group::factory()->create();
        $member = $this->joined($group);
        TimelinePost::factory()->inGroup($group)->create(['member_id' => $member->getKey()]);

        // The first request of the process pays for warm-up (settings, feature resolution) that the
        // rest read from cache, so it is spent before either measurement rather than measured.
        $this->actingAs($member)->get("/groups/{$group->getKey()}/timeline")->assertOk();

        $one = $this->countQueries(fn () => $this->actingAs($member)->get("/groups/{$group->getKey()}/timeline")->assertOk());

        TimelinePost::factory()->inGroup($group)->count(9)->create(['member_id' => $member->getKey()]);

        $ten = $this->countQueries(fn () => $this->actingAs($member)->get("/groups/{$group->getKey()}/timeline")->assertOk());

        $this->assertSame($one, $ten, "the page grew from {$one} to {$ten} queries between 1 and 10 rows");
    }

    private function countQueries(callable $request): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });
        $request();

        return $count;
    }

    private function joined(Group $group): Member
    {
        $member = Member::factory()->create();
        GroupMember::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
            'role' => GroupRole::Member,
        ]);

        return $member;
    }
}
