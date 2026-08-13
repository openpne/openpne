<?php

namespace Tests\Feature\CommunityEvent\Modern;

use App\Features\Group\GroupRole;
use App\Features\GroupTopic\TopicReadAccess;
use App\Models\CommunityEvent;
use App\Models\CommunityEventComment;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use App\Support\BodyFormat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunityEventRoutesTest extends TestCase
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
        $group = Group::factory()->create();
        $event = CommunityEvent::factory()->create(['community_id' => $group->getKey()]);

        $this->get(route('communityEvent.index', $group))->assertRedirect('/login');
        $this->get(route('communityEvent.new', $group))->assertRedirect('/login');
        $this->get(route('communityEvent.show', $event))->assertRedirect('/login');
        $this->get(route('communityEvent.member_list', $event))->assertRedirect('/login');
        $this->post(route('communityEvent.store', $group))->assertRedirect('/login');
        $this->post(route('communityEvent.delete', $event))->assertRedirect('/login');
    }

    public function test_modern_index_renders_the_board(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);
        CommunityEvent::factory()->create(['community_id' => $group->getKey(), 'member_id' => $member->getKey()]);

        $this->actingAs($member)
            ->get(route('communityEvent.index', $group))
            ->assertInertia(fn ($page) => $page
                ->component('community/event/index')
                ->where('group.id', $group->getKey())
                ->has('events.data', 1)
                ->has('events.data.0.openDate')
                ->where('canPost', true)
            );
    }

    public function test_modern_show_renders_the_event_with_rsvp_state(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $event = CommunityEvent::factory()->create(['community_id' => $group->getKey(), 'member_id' => $author->getKey()]);
        CommunityEventComment::factory()->create(['community_event_id' => $event->getKey(), 'member_id' => $author->getKey(), 'number' => 1]);

        $this->actingAs($author)
            ->get(route('communityEvent.show', $event))
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
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        $event = CommunityEvent::factory()->create(['community_id' => $group->getKey()]);
        $stranger = Member::factory()->create();

        $this->actingAs($stranger)->get(route('communityEvent.show', $event))->assertNotFound();
    }

    public function test_modern_new_renders_the_form_for_a_member(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);

        $this->actingAs($member)
            ->get(route('communityEvent.new', $group))
            ->assertInertia(fn ($page) => $page
                ->component('community/event/edit')
                ->where('group.id', $group->getKey())
                ->where('event', null)
                ->where('composeEditor', 'rich')
            );
    }

    public function test_modern_new_returns_404_for_a_non_member(): void
    {
        $group = Group::factory()->create();
        $stranger = Member::factory()->create();

        $this->actingAs($stranger)->get(route('communityEvent.new', $group))->assertNotFound();
    }

    public function test_modern_store_creates_an_event_and_redirects_to_show(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);

        $response = $this->actingAs($member)->post(route('communityEvent.store', $group), $this->eventPayload());

        $event = CommunityEvent::where('name', 'Modern Meetup')->firstOrFail();
        $response->assertRedirect(route('communityEvent.show', $event));
        $this->assertDatabaseHas('community_events', [
            'id' => $event->getKey(),
            'community_id' => $group->getKey(),
            'member_id' => $member->getKey(),
            'area' => 'Tokyo',
        ]);
    }

    public function test_modern_edit_renders_the_form_with_ymd_dates_for_the_author(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $event = CommunityEvent::factory()->create([
            'community_id' => $group->getKey(),
            'member_id' => $author->getKey(),
            'open_date' => now()->addWeek()->startOfDay(),
            'format' => BodyFormat::Markdown,
        ]);

        $this->actingAs($author)
            ->get(route('communityEvent.edit', $event))
            ->assertInertia(fn ($page) => $page
                ->component('community/event/edit')
                ->where('event.id', $event->getKey())
                ->where('event.openDate', now()->addWeek()->format('Y-m-d'))
                // The edit page resolves its input method from this prop (the slim edit shape must carry it).
                ->where('event.format', 'markdown')
                ->where('composeEditor', 'rich')
            );
    }

    public function test_modern_edit_returns_404_for_a_non_editor(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $event = CommunityEvent::factory()->create(['community_id' => $group->getKey(), 'member_id' => $author->getKey()]);
        $stranger = Member::factory()->create();

        $this->actingAs($stranger)->get(route('communityEvent.edit', $event))->assertNotFound();
    }

    public function test_modern_update_edits_the_event_and_redirects_to_show(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $event = CommunityEvent::factory()->create(['community_id' => $group->getKey(), 'member_id' => $author->getKey()]);

        $this->actingAs($author)
            ->post(route('communityEvent.update', $event), $this->eventPayload(['name' => 'Renamed', 'area' => 'Osaka']))
            ->assertRedirect(route('communityEvent.show', $event));

        $this->assertDatabaseHas('community_events', ['id' => $event->getKey(), 'name' => 'Renamed', 'area' => 'Osaka']);
    }

    public function test_modern_delete_removes_the_event_and_redirects_to_the_community(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $event = CommunityEvent::factory()->create(['community_id' => $group->getKey(), 'member_id' => $author->getKey()]);

        $this->actingAs($author)
            ->post(route('communityEvent.delete', $event))
            ->assertRedirect(route('group.show', $group));

        $this->assertDatabaseMissing('community_events', ['id' => $event->getKey()]);
    }

    public function test_modern_delete_returns_404_for_a_non_editor(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $event = CommunityEvent::factory()->create(['community_id' => $group->getKey(), 'member_id' => $author->getKey()]);
        $stranger = Member::factory()->create();

        $this->actingAs($stranger)->post(route('communityEvent.delete', $event))->assertNotFound();
        $this->assertDatabaseHas('community_events', ['id' => $event->getKey()]);
    }

    public function test_modern_member_list_renders_the_roster(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);
        $event = CommunityEvent::factory()->create(['community_id' => $group->getKey(), 'member_id' => $member->getKey()]);
        $event->participants()->attach($member);

        $this->actingAs($member)
            ->get(route('communityEvent.member_list', $event))
            ->assertInertia(fn ($page) => $page
                ->component('community/event/members')
                ->where('group.id', $group->getKey())
                ->where('event.id', $event->getKey())
                ->where('participants.data.0.id', $member->getKey())
            );
    }

    public function test_date_only_event_fields_are_serialized_as_ymd(): void
    {
        // Date-only fields must be Y-m-d, not an ISO midnight a browser would render a day early west
        // of UTC. Assert the show, board, and community recent-events props all carry the plain date.
        $group = Group::factory()->create();
        $member = $this->joined($group);
        $openDate = now()->addMonth()->startOfDay();
        $event = CommunityEvent::factory()->create([
            'community_id' => $group->getKey(),
            'member_id' => $member->getKey(),
            'open_date' => $openDate,
            'application_deadline' => $openDate->copy()->subDay(),
        ]);
        $openYmd = $openDate->format('Y-m-d');
        $deadlineYmd = $openDate->copy()->subDay()->format('Y-m-d');

        $this->actingAs($member)
            ->get(route('communityEvent.show', $event))
            ->assertInertia(fn ($page) => $page
                ->where('event.openDate', $openYmd)
                ->where('event.applicationDeadline', $deadlineYmd)
            );

        $this->actingAs($member)
            ->get(route('communityEvent.index', $group))
            ->assertInertia(fn ($page) => $page->where('events.data.0.openDate', $openYmd));

        $this->actingAs($member)
            ->get(route('group.show', $group))
            ->assertInertia(fn ($page) => $page->where('recentEvents.0.openDate', $openYmd));
    }

    public function test_participant_count_is_serialized_on_the_board_and_community_show(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $event = CommunityEvent::factory()->create(['community_id' => $group->getKey(), 'member_id' => $author->getKey()]);
        $event->participants()->attach([$author->getKey(), $this->joined($group)->getKey()]);

        $this->actingAs($author)
            ->get(route('communityEvent.index', $group))
            ->assertInertia(fn ($page) => $page->where('events.data.0.participantCount', 2));

        $this->actingAs($author)
            ->get(route('group.show', $group))
            ->assertInertia(fn ($page) => $page
                ->where('recentEvents.0.participantCount', 2)
                ->where('recentEvents.0.author.name', $author->name)
            );
    }

    public function test_modern_only_serves_the_canonical_event_board_as_inertia(): void
    {
        config()->set('openpne.surface_mode', 'modern_only');
        $group = Group::factory()->create();
        $member = $this->joined($group);

        $this->actingAs($member)
            ->get(route('communityEvent.index', $group))
            ->assertInertia(fn ($page) => $page->component('community/event/index'));
    }
}
