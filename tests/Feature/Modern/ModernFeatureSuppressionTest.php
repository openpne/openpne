<?php

declare(strict_types=1);

namespace Tests\Feature\Modern;

use App\Models\Community;
use App\Models\CommunityEvent;
use App\Models\CommunityMember;
use App\Models\CommunityTopic;
use App\Models\Diary;
use App\Models\Member;
use App\Models\Message;
use App\Models\MessageRecipient;
use App\Models\TimelinePost;
use App\Support\Feature;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * A switched-off unit leaves the Inertia payload, not just the screen: every case here seeds rows
 * that DO reach the payload while the unit is on, so an assertion proves the emptying rather than
 * an empty fixture. The client-side hiding is presentation only, and is not what this pins.
 */
class ModernFeatureSuppressionTest extends TestCase
{
    use RefreshDatabase;

    private function joinedCommunity(Member $member): Community
    {
        $community = Community::factory()->create();
        CommunityMember::factory()->member()->create([
            'community_id' => $community->getKey(),
            'member_id' => $member->getKey(),
        ]);

        return $community;
    }

    private function makeFriends(Member $a, Member $b): void
    {
        DB::table('friendships')->insert([
            ['member_id' => $a->getKey(), 'friend_id' => $b->getKey()],
            ['member_id' => $b->getKey(), 'friend_id' => $a->getKey()],
        ]);
    }

    /** Switch a unit off mid-test: the container outlives a request here, so drop the scoped memos. */
    private function switchOff(Feature $feature): void
    {
        $this->setSnsSetting($feature->settingKey(), false);
        $this->freshRequestState();
    }

    // --- Dashboard ---

    public function test_the_dashboard_drops_both_diary_digests(): void
    {
        $viewer = Member::factory()->create();
        Diary::factory()->create(['visibility' => Visibility::Members, 'title' => 'all-members-diary-row']);
        Diary::factory()->create([
            'member_id' => $viewer->getKey(),
            'visibility' => Visibility::Private,
            'title' => 'my-own-diary-row',
        ]);

        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('diaries', 1)->has('myDiaries', 1));

        $this->switchOff(Feature::Diary);

        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('diaries', [])
                ->where('myDiaries', []))
            ->assertDontSee('all-members-diary-row')
            ->assertDontSee('my-own-diary-row');
    }

    public function test_the_dashboard_drops_the_timeline_feed(): void
    {
        $viewer = Member::factory()->create();
        TimelinePost::factory()->create([
            'member_id' => $viewer->getKey(),
            'visibility' => Visibility::Members,
            'body' => 'a-timeline-body-row',
        ]);

        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('timeline', 1));

        $this->switchOff(Feature::Timeline);

        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('timeline', []))
            ->assertDontSee('a-timeline-body-row');
    }

    public function test_the_dashboard_drops_the_community_activity_and_the_approval_notices(): void
    {
        $viewer = Member::factory()->create();
        $joined = $this->joinedCommunity($viewer);
        CommunityTopic::factory()->create(['community_id' => $joined->getKey(), 'name' => 'a-topic-row-name']);

        $administered = Community::factory()->create();
        CommunityMember::factory()->admin()->create(['community_id' => $administered->getKey(), 'member_id' => $viewer->getKey()]);
        $administered->applicants()->attach(Member::factory()->create()->getKey());

        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('communityActivity', 1)
                ->has('announcements.communityApprovals', 1));

        $this->switchOff(Feature::Community);

        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('communityActivity', [])
                ->where('announcements.communityApprovals', []))
            ->assertDontSee('a-topic-row-name');
    }

    public function test_the_dashboard_keeps_the_events_when_only_the_board_is_off(): void
    {
        $viewer = Member::factory()->create();
        $community = $this->joinedCommunity($viewer);
        CommunityTopic::factory()->create(['community_id' => $community->getKey()]);
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey()]);

        $this->switchOff(Feature::CommunityTopic);

        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('communityActivity', 1)
                ->where('communityActivity.0.kind', 'event')
                ->where('communityActivity.0.id', $event->getKey()));

        $this->actingAs($viewer)->get('/community/recent')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('activity', 1)
                ->where('activity.0.kind', 'event'));
    }

    public function test_the_dashboard_notices_follow_the_friend_and_message_units(): void
    {
        $viewer = Member::factory()->create();
        $other = Member::factory()->create();
        DB::table('friend_requests')->insert(['requester_id' => $other->getKey(), 'target_id' => $viewer->getKey()]);
        $message = Message::factory()->create(['sender_id' => $other->getKey()]);
        MessageRecipient::factory()->create(['message_id' => $message->getKey(), 'recipient_id' => $viewer->getKey()]);

        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('announcements.friendRequests', 1)
                ->where('announcements.unreadMessages', 1));

        $this->switchOff(Feature::Friend);
        $this->switchOff(Feature::Message);

        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('announcements.friendRequests', 0)
                ->where('announcements.unreadMessages', 0)
                ->where('unread.friendRequests', 0)
                ->where('unread.unreadMessages', 0));
    }

    // --- Profile digest ---

    public function test_the_profile_drops_the_friend_status_and_the_friends_grid(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        $this->makeFriends($owner, Member::factory()->create());

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('profile.friendStatus', 'none')
                ->has('digest.friends', 1)
                ->where('digest.stats.friends', 1));

        $this->switchOff(Feature::Friend);

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('profile.friendStatus', null)
                ->where('digest.friends', [])
                ->where('digest.stats.friends', 0));
    }

    public function test_the_profile_drops_the_recent_diaries(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        Diary::factory()->create([
            'member_id' => $owner->getKey(),
            'visibility' => Visibility::Members,
            'title' => 'an-owner-diary-title',
        ]);

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('digest.recentDiaries', 1)
                ->where('digest.stats.diaries', 1));

        $this->switchOff(Feature::Diary);

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('digest.recentDiaries', [])
                ->where('digest.stats.diaries', 0))
            ->assertDontSee('an-owner-diary-title');
    }

    public function test_the_profile_drops_the_joined_communities_grid(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        $community = $this->joinedCommunity($owner);

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('digest.communities', 1)
                ->where('digest.communities.0.id', $community->getKey())
                ->where('digest.stats.communities', 1));

        $this->switchOff(Feature::Community);

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('digest.communities', [])
                ->where('digest.stats.communities', 0));
    }

    public function test_the_profile_zeroes_the_activity_stat_on_its_own(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        TimelinePost::factory()->create(['member_id' => $owner->getKey(), 'visibility' => Visibility::Members]);
        Diary::factory()->create(['member_id' => $owner->getKey(), 'visibility' => Visibility::Members]);

        $this->switchOff(Feature::Timeline);

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('digest.stats.activity', 0)
                ->where('digest.stats.diaries', 1)); // the neighbouring unit is untouched
    }

    // --- Shell + settings ---

    /**
     * The exception to the emptying above: the faces grid's purpose outlives `friend`, so it falls
     * back to all members instead of vanishing (docs/internals/feature-toggles.md). The grid beside
     * it, whose purpose is its unit, still empties.
     */
    public function test_the_right_rail_falls_back_to_all_members_and_drops_the_community_grid(): void
    {
        $viewer = Member::factory()->create();
        $friend = Member::factory()->create();
        $stranger = Member::factory()->create();
        $this->makeFriends($viewer, $friend);
        $this->joinedCommunity($viewer);

        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('rightRail.people.kind', 'friends')
                ->has('rightRail.people.items', 1)
                ->where('rightRail.people.items.0.id', $friend->getKey())
                ->has('rightRail.joinedCommunities', 1));

        $this->switchOff(Feature::Friend);

        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('rightRail.people.kind', 'members')
                // The stranger the friends grid never carried is in the fallback pool; the viewer is not.
                ->has('rightRail.people.items', 2)
                ->where('rightRail.people.items', fn (Collection $items) => $items->pluck('id')
                    ->contains($friend->getKey()) && $items->pluck('id')->contains($stranger->getKey()))
                ->has('rightRail.joinedCommunities', 1));

        $this->switchOff(Feature::Community);

        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('rightRail.people.items', 2)
                ->where('rightRail.joinedCommunities', []));
    }

    public function test_the_member_settings_omit_the_diary_section(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/member/config')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('form.diary.options'));

        $this->switchOff(Feature::Diary);

        $this->actingAs($member)->get('/member/config')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->missing('form.diary')
                ->has('form.locale')); // the rest of the page is untouched
    }
}
