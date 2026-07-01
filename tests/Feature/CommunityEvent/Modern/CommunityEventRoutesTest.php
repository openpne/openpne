<?php

namespace Tests\Feature\CommunityEvent\Modern;

use App\Features\Community\CommunityRole;
use App\Features\CommunityTopic\TopicReadAccess;
use App\Models\Community;
use App\Models\CommunityEvent;
use App\Models\CommunityEventComment;
use App\Models\CommunityMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunityEventRoutesTest extends TestCase
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

    /** @return array<string, mixed> */
    private function eventPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Modern Meetup',
            'body' => 'Meet at the gate.',
            'open_date' => now()->addWeek()->format('Y-m-d'),
            'open_date_comment' => '10:00 start',
            'area' => 'Tokyo',
        ], $overrides);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $community = Community::factory()->create();
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey()]);

        $this->get(route('communityEvent.modern.index', $community))->assertRedirect('/login');
        $this->get(route('communityEvent.modern.new', $community))->assertRedirect('/login');
        $this->get(route('communityEvent.modern.show', $event))->assertRedirect('/login');
        $this->get(route('communityEvent.modern.member_list', $event))->assertRedirect('/login');
        $this->post(route('communityEvent.modern.store', $community))->assertRedirect('/login');
        $this->post(route('communityEvent.modern.delete', $event))->assertRedirect('/login');
    }

    public function test_modern_index_renders_the_board(): void
    {
        $community = Community::factory()->create();
        $member = $this->joined($community);
        CommunityEvent::factory()->create(['community_id' => $community->getKey(), 'member_id' => $member->getKey()]);

        $this->actingAs($member)
            ->get(route('communityEvent.modern.index', $community))
            ->assertInertia(fn ($page) => $page
                ->component('community/event/index')
                ->where('community.id', $community->getKey())
                ->has('events.data', 1)
                ->has('events.data.0.openDate')
                ->where('canPost', true)
            );
    }

    public function test_modern_show_renders_the_event_with_rsvp_state(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);
        CommunityEventComment::factory()->create(['community_event_id' => $event->getKey(), 'member_id' => $author->getKey(), 'number' => 1]);

        $this->actingAs($author)
            ->get(route('communityEvent.modern.show', $event))
            ->assertInertia(fn ($page) => $page
                ->component('community/event/show')
                ->where('event.id', $event->getKey())
                ->where('thread.total', 1)
                ->has('thread.comments', 1)
                ->where('isParticipant', false)
                ->where('rosterOpen', true)
                ->where('isFull', false)
                ->where('canComment', true)
                ->where('canEdit', true)
            );
    }

    public function test_modern_show_returns_404_when_events_are_members_only_and_the_viewer_is_a_stranger(): void
    {
        $community = Community::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey()]);
        $stranger = Member::factory()->create();

        $this->actingAs($stranger)->get(route('communityEvent.modern.show', $event))->assertNotFound();
    }

    public function test_modern_new_renders_the_form_for_a_member(): void
    {
        $community = Community::factory()->create();
        $member = $this->joined($community);

        $this->actingAs($member)
            ->get(route('communityEvent.modern.new', $community))
            ->assertInertia(fn ($page) => $page
                ->component('community/event/edit')
                ->where('community.id', $community->getKey())
                ->where('event', null)
            );
    }

    public function test_modern_new_returns_404_for_a_non_member(): void
    {
        $community = Community::factory()->create();
        $stranger = Member::factory()->create();

        $this->actingAs($stranger)->get(route('communityEvent.modern.new', $community))->assertNotFound();
    }

    public function test_modern_store_creates_an_event_and_redirects_to_modern_show(): void
    {
        $community = Community::factory()->create();
        $member = $this->joined($community);

        $response = $this->actingAs($member)->post(route('communityEvent.modern.store', $community), $this->eventPayload());

        $event = CommunityEvent::where('name', 'Modern Meetup')->firstOrFail();
        $response->assertRedirect(route('communityEvent.modern.show', $event));
        $this->assertDatabaseHas('community_events', [
            'id' => $event->getKey(),
            'community_id' => $community->getKey(),
            'member_id' => $member->getKey(),
            'area' => 'Tokyo',
        ]);
    }

    public function test_modern_edit_renders_the_form_with_ymd_dates_for_the_author(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $event = CommunityEvent::factory()->create([
            'community_id' => $community->getKey(),
            'member_id' => $author->getKey(),
            'open_date' => now()->addWeek()->startOfDay(),
        ]);

        $this->actingAs($author)
            ->get(route('communityEvent.modern.edit', $event))
            ->assertInertia(fn ($page) => $page
                ->component('community/event/edit')
                ->where('event.id', $event->getKey())
                ->where('event.openDate', now()->addWeek()->format('Y-m-d'))
            );
    }

    public function test_modern_edit_returns_404_for_a_non_editor(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);
        $stranger = Member::factory()->create();

        $this->actingAs($stranger)->get(route('communityEvent.modern.edit', $event))->assertNotFound();
    }

    public function test_modern_update_edits_the_event_and_redirects_to_modern_show(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);

        $this->actingAs($author)
            ->post(route('communityEvent.modern.update', $event), $this->eventPayload(['name' => 'Renamed', 'area' => 'Osaka']))
            ->assertRedirect(route('communityEvent.modern.show', $event));

        $this->assertDatabaseHas('community_events', ['id' => $event->getKey(), 'name' => 'Renamed', 'area' => 'Osaka']);
    }

    public function test_modern_delete_removes_the_event_and_redirects_to_the_modern_community(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);

        $this->actingAs($author)
            ->post(route('communityEvent.modern.delete', $event))
            ->assertRedirect(route('community.modern.show', $community));

        $this->assertDatabaseMissing('community_events', ['id' => $event->getKey()]);
    }

    public function test_modern_delete_returns_404_for_a_non_editor(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);
        $stranger = Member::factory()->create();

        $this->actingAs($stranger)->post(route('communityEvent.modern.delete', $event))->assertNotFound();
        $this->assertDatabaseHas('community_events', ['id' => $event->getKey()]);
    }

    public function test_modern_member_list_renders_the_roster(): void
    {
        $community = Community::factory()->create();
        $member = $this->joined($community);
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey(), 'member_id' => $member->getKey()]);
        $event->participants()->attach($member);

        $this->actingAs($member)
            ->get(route('communityEvent.modern.member_list', $event))
            ->assertInertia(fn ($page) => $page
                ->component('community/event/members')
                ->where('event.id', $event->getKey())
                ->where('participants.data.0.id', $member->getKey())
            );
    }

    public function test_date_only_event_fields_are_serialized_as_ymd(): void
    {
        // Date-only fields must be Y-m-d, not an ISO midnight a browser would render a day early west
        // of UTC. Assert the show, board, and community recent-events props all carry the plain date.
        $community = Community::factory()->create();
        $member = $this->joined($community);
        $openDate = now()->addMonth()->startOfDay();
        $event = CommunityEvent::factory()->create([
            'community_id' => $community->getKey(),
            'member_id' => $member->getKey(),
            'open_date' => $openDate,
            'application_deadline' => $openDate->copy()->subDay(),
        ]);
        $openYmd = $openDate->format('Y-m-d');
        $deadlineYmd = $openDate->copy()->subDay()->format('Y-m-d');

        $this->actingAs($member)
            ->get(route('communityEvent.modern.show', $event))
            ->assertInertia(fn ($page) => $page
                ->where('event.openDate', $openYmd)
                ->where('event.applicationDeadline', $deadlineYmd)
            );

        $this->actingAs($member)
            ->get(route('communityEvent.modern.index', $community))
            ->assertInertia(fn ($page) => $page->where('events.data.0.openDate', $openYmd));

        $this->actingAs($member)
            ->get(route('community.modern.show', $community))
            ->assertInertia(fn ($page) => $page->where('recentEvents.0.openDate', $openYmd));
    }

    public function test_modern_only_serves_the_canonical_event_board_as_inertia(): void
    {
        config()->set('openpne.tenant_mode', 'modern_only');
        $community = Community::factory()->create();
        $member = $this->joined($community);

        $this->actingAs($member)
            ->get(route('communityEvent.index', $community))
            ->assertInertia(fn ($page) => $page->component('community/event/index'));
    }
}
