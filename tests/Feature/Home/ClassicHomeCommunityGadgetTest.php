<?php

namespace Tests\Feature\Home;

use App\Features\CommunityTopic\TopicReadAccess;
use App\Models\Community;
use App\Models\CommunityEvent;
use App\Models\CommunityEventComment;
use App\Models\CommunityEventMember;
use App\Models\CommunityMember;
use App\Models\CommunityTopic;
use App\Models\CommunityTopicComment;
use App\Models\Gadget;
use App\Models\GadgetConfig;
use App\Models\Member;
use App\Services\GadgetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The OpenPNE 3 opCommunityTopicPlugin home recent-list gadgets: recentCommunityTopicComment,
 * recentCommunityEventComment, and their SNS-wide (public communities) variants.
 */
class ClassicHomeCommunityGadgetTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, string|int> $config */
    private function makeGadget(string $name, array $config = []): Gadget
    {
        $gadget = Gadget::create(['context' => 'home', 'zone' => 'contents', 'name' => $name, 'sort_order' => 0]);
        foreach ($config as $key => $value) {
            GadgetConfig::create(['gadget_id' => $gadget->id, 'name' => $key, 'value' => (string) $value]);
        }
        app(GadgetService::class)->clearCache();

        return $gadget;
    }

    private function join(Member $member, Community $community): void
    {
        CommunityMember::factory()->member()->create([
            'community_id' => $community->getKey(),
            'member_id' => $member->getKey(),
        ]);
    }

    public function test_recent_community_topic_comment_renders_the_openpne3_dom(): void
    {
        $viewer = Member::factory()->create();
        $community = Community::factory()->create(['name' => 'JoinedCommunity']);
        $this->join($viewer, $community);
        $topic = CommunityTopic::factory()->create([
            'community_id' => $community->getKey(),
            'name' => 'JoinedTopic',
            'updated_at' => '2026-03-04 12:00:00',
        ]);
        CommunityTopicComment::factory()->create(['community_topic_id' => $topic->getKey()]);
        $gadget = $this->makeGadget('recentCommunityTopicComment');

        $this->actingAs($viewer)->get('/')
            ->assertOk()
            ->assertSee('id="homeRecentList_'.$gadget->id.'"', false)
            ->assertSee('class="dparts homeRecentList"', false)
            ->assertSee('Recently Posted Community Topics')        // h3 (en term rendering)
            ->assertSee('JoinedTopic(1)')                          // title + count, no separating space
            ->assertSee('(JoinedCommunity)')                       // community name follows the link
            ->assertSee('March 4')                                 // updated_at, not created_at
            ->assertSee('/communityTopic/'.$topic->getKey(), false)
            ->assertDontSee('moreInfo', false);                    // no More link (parity gap)
    }

    public function test_recent_community_topic_comment_hides_topics_from_communities_the_viewer_has_not_joined(): void
    {
        $viewer = Member::factory()->create();
        $joined = Community::factory()->create();
        $other = Community::factory()->create();
        $this->join($viewer, $joined);
        CommunityTopic::factory()->create(['community_id' => $joined->getKey(), 'name' => 'JoinedTopic']);
        CommunityTopic::factory()->create(['community_id' => $other->getKey(), 'name' => 'StrangerTopic']);
        $this->makeGadget('recentCommunityTopicComment');

        $this->actingAs($viewer)->get('/')
            ->assertOk()
            ->assertSee('JoinedTopic(0)')
            ->assertDontSee('StrangerTopic');
    }

    public function test_recent_community_topic_comment_is_dropped_when_empty(): void
    {
        $viewer = Member::factory()->create();
        $this->makeGadget('recentCommunityTopicComment');

        $this->actingAs($viewer)->get('/')
            ->assertOk()
            ->assertDontSee('homeRecentList', false);
    }

    public function test_recent_community_topic_comment_honors_the_col_config_and_ignores_the_page_query(): void
    {
        $viewer = Member::factory()->create();
        $community = Community::factory()->create();
        $this->join($viewer, $community);
        CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'name' => 'OldTopic', 'updated_at' => '2026-01-01 00:00:00']);
        CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'name' => 'NewTopic', 'updated_at' => '2026-03-01 00:00:00']);
        $this->makeGadget('recentCommunityTopicComment', ['col' => 1]);

        // col=1 keeps only the newest; limit()->get() ignores the host page's ?page=.
        $this->actingAs($viewer)->get('/?page=2')
            ->assertOk()
            ->assertSee('NewTopic(0)')
            ->assertDontSee('OldTopic');
    }

    public function test_recent_community_event_comment_counts_comments_not_participants(): void
    {
        $viewer = Member::factory()->create();
        $community = Community::factory()->create(['name' => 'EventCommunity']);
        $this->join($viewer, $community);
        $event = CommunityEvent::factory()->create([
            'community_id' => $community->getKey(),
            'name' => 'JoinedEvent',
            'updated_at' => '2026-03-04 12:00:00',
        ]);
        CommunityEventComment::factory()->count(2)->create(['community_event_id' => $event->getKey()]);
        CommunityEventMember::factory()->count(3)->create(['community_event_id' => $event->getKey()]);
        $gadget = $this->makeGadget('recentCommunityEventComment');

        $this->actingAs($viewer)->get('/')
            ->assertOk()
            ->assertSee('id="homeRecentList_'.$gadget->id.'"', false)
            ->assertSee('class="dparts homeRecentList"', false)
            ->assertSee('Recently Posted Community Events')     // h3
            ->assertSee('JoinedEvent(2)')                       // comment count (2), not participant count (3)
            ->assertSee('(EventCommunity)')
            ->assertSee('/communityEvent/'.$event->getKey(), false)
            ->assertDontSee('moreInfo', false);
    }

    public function test_recent_community_topic_comment_sns_shows_public_communities_with_its_own_part_class(): void
    {
        $viewer = Member::factory()->create();
        $public = Community::factory()->create(['name' => 'PublicCommunity', 'topic_read_access' => TopicReadAccess::Everyone]);
        $membersOnly = Community::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        CommunityTopic::factory()->create(['community_id' => $public->getKey(), 'name' => 'PublicTopic']);
        CommunityTopic::factory()->create(['community_id' => $membersOnly->getKey(), 'name' => 'PrivateTopic']);
        // The viewer joins neither: this gadget is viewer-independent.
        $this->makeGadget('recentCommunityTopicCommentSns');

        $this->actingAs($viewer)->get('/')
            ->assertOk()
            ->assertSee('class="dparts topicRecentList homeRecentList"', false) // SNS-only parts-name class
            ->assertSee('Latest community topics across the SNS')               // h3
            ->assertSee('PublicTopic(0)')
            ->assertSee('(PublicCommunity)')
            ->assertDontSee('PrivateTopic');                                    // members-only community excluded
    }

    public function test_recent_community_event_comment_sns_shows_public_communities_with_its_own_part_class(): void
    {
        $viewer = Member::factory()->create();
        $public = Community::factory()->create(['topic_read_access' => TopicReadAccess::Everyone]);
        $membersOnly = Community::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        CommunityEvent::factory()->create(['community_id' => $public->getKey(), 'name' => 'PublicEvent']);
        CommunityEvent::factory()->create(['community_id' => $membersOnly->getKey(), 'name' => 'PrivateEvent']);
        $this->makeGadget('recentCommunityEventCommentSns');

        $this->actingAs($viewer)->get('/')
            ->assertOk()
            ->assertSee('class="dparts eventRecentList homeRecentList"', false)
            ->assertSee('Latest community events across the SNS')
            ->assertSee('PublicEvent(0)')
            ->assertDontSee('PrivateEvent');
    }

    public function test_japanese_headings_match_openpne3(): void
    {
        $viewer = Member::factory()->create(['locale' => 'ja']);
        $community = Community::factory()->create(['topic_read_access' => TopicReadAccess::Everyone]);
        $this->join($viewer, $community);
        CommunityTopic::factory()->create(['community_id' => $community->getKey()]);
        $this->makeGadget('recentCommunityTopicComment');

        $this->actingAs($viewer)->get('/')
            ->assertOk()
            ->assertSee('コミュニティ最新書き込み');
    }
}
