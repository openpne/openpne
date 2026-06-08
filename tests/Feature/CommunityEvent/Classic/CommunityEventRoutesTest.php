<?php

namespace Tests\Feature\CommunityEvent\Classic;

use App\Features\Community\CommunityRole;
use App\Features\CommunityTopic\TopicPostAuthority;
use App\Features\CommunityTopic\TopicReadAccess;
use App\Models\Community;
use App\Models\CommunityEvent;
use App\Models\CommunityEventComment;
use App\Models\CommunityMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            'name' => 'Morning run',
            'body' => 'Meet at the gate.',
            'open_date' => now()->addWeek()->format('Y-m-d'),
            'open_date_comment' => '07:00 start',
            'area' => 'Yoyogi Park',
            'application_deadline' => null,
            'capacity' => null,
        ], $overrides);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $community = Community::factory()->create();
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey()]);

        $this->get(route('communityEvent.index', $community))->assertRedirect('/login');
        $this->get(route('communityEvent.show', $event))->assertRedirect('/login');
        $this->post(route('communityEvent.store', $community))->assertRedirect('/login');
    }

    public function test_board_renders_with_body_id_and_most_recent_activity_first(): void
    {
        $community = Community::factory()->create();
        $stale = CommunityEvent::factory()->create(['community_id' => $community->getKey(), 'name' => 'Stale event']);
        $fresh = CommunityEvent::factory()->create(['community_id' => $community->getKey(), 'name' => 'Fresh event']);
        DB::table('community_events')->where('id', $stale->getKey())->update(['updated_at' => now()->subDays(3)]);

        $response = $this->actingAs($this->joined($community))->get(route('communityEvent.index', $community));

        $response->assertOk();
        $response->assertSee('id="page_communityEvent_listCommunity"', false);
        // Board order is updated_at DESC (activity), not open_date.
        $response->assertSeeInOrder(['Fresh event', 'Stale event']);
    }

    public function test_board_shows_comment_counts(): void
    {
        $community = Community::factory()->create();
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey(), 'name' => 'Counted']);
        CommunityEventComment::factory()->count(2)->sequence(['number' => 1], ['number' => 2])
            ->create(['community_event_id' => $event->getKey()]);

        $response = $this->actingAs($this->joined($community))->get(route('communityEvent.index', $community));

        $response->assertOk();
        $response->assertSee('Counted (2)');
    }

    public function test_members_only_board_is_hidden_from_non_members(): void
    {
        $community = Community::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey()]);
        $stranger = Member::factory()->create();

        $this->actingAs($stranger)->get(route('communityEvent.index', $community))->assertNotFound();
        $this->actingAs($stranger)->get(route('communityEvent.show', $event))->assertNotFound();
        $this->actingAs($stranger)->get(route('communityEvent.member_list', $event))->assertNotFound();

        $this->actingAs($this->joined($community))->get(route('communityEvent.show', $event))->assertOk();
    }

    public function test_show_renders_event_with_body_id_and_scheduling_details(): void
    {
        $community = Community::factory()->create();
        $event = CommunityEvent::factory()->create([
            'community_id' => $community->getKey(),
            'name' => 'Hello event',
            'body' => 'Come along.',
            'area' => 'Shibuya Hall',
            'capacity' => 30,
        ]);

        $response = $this->actingAs($this->joined($community))->get(route('communityEvent.show', $event));

        $response->assertOk();
        $response->assertSee('id="page_communityEvent_show"', false);
        $response->assertSee('Hello event');
        $response->assertSee('Come along.');
        $response->assertSee('Shibuya Hall');
        $response->assertSee('30');
    }

    public function test_show_for_unknown_event_returns_404(): void
    {
        $this->actingAs(Member::factory()->create())->get('/communityEvent/999999')->assertNotFound();
    }

    public function test_a_non_numeric_literal_is_not_swallowed_by_the_event_wildcard(): void
    {
        // /communityEvent/search is the (un-ported) shared search URL; it must not resolve to the
        // numeric event show as if "search" were an id.
        $this->actingAs(Member::factory()->create())->get('/communityEvent/search')->assertNotFound();
    }

    public function test_new_event_is_admin_only_when_posting_is_restricted(): void
    {
        $community = Community::factory()->create(['topic_post_authority' => TopicPostAuthority::AdminsOnly]);
        $member = $this->joined($community, CommunityRole::Member);
        $admin = $this->joined($community, CommunityRole::Admin);

        $this->actingAs($member)->get(route('communityEvent.new', $community))->assertNotFound();
        $this->actingAs($member)->post(route('communityEvent.store', $community), $this->eventPayload())->assertNotFound();

        $this->actingAs($admin)->get(route('communityEvent.new', $community))
            ->assertOk()
            ->assertSee('id="page_communityEvent_new"', false);
    }

    public function test_a_member_posts_an_event_and_is_redirected_to_it(): void
    {
        $community = Community::factory()->create();
        $member = $this->joined($community);

        $response = $this->actingAs($member)->post(route('communityEvent.store', $community), $this->eventPayload([
            'name' => 'Welcome party',
            'capacity' => 20,
        ]));

        $event = CommunityEvent::where('name', 'Welcome party')->firstOrFail();
        $response->assertRedirect(route('communityEvent.show', $event));
        $this->assertSame($member->getKey(), $event->member_id);
        $this->assertSame($community->getKey(), $event->community_id);
        $this->assertSame('Yoyogi Park', $event->area);
        $this->assertSame(20, $event->capacity);
    }

    public function test_an_unauthorized_poster_gets_404_even_with_an_invalid_payload(): void
    {
        $community = Community::factory()->create();
        $stranger = Member::factory()->create();

        $this->actingAs($stranger)->post(route('communityEvent.store', $community), ['name' => '', 'body' => ''])
            ->assertNotFound();
        $this->assertDatabaseCount('community_events', 0);
    }

    public function test_editing_an_event_is_limited_to_its_author_and_admins(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $admin = $this->joined($community, CommunityRole::Admin);
        $other = $this->joined($community);
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);

        $this->actingAs($other)->get(route('communityEvent.edit', $event))->assertNotFound();
        $this->actingAs($admin)->get(route('communityEvent.edit', $event))->assertOk()
            ->assertSee('id="page_communityEvent_edit"', false);

        $response = $this->actingAs($author)->post(route('communityEvent.update', $event), $this->eventPayload([
            'name' => 'Edited title',
            'body' => $event->body,
            'open_date' => $event->open_date->format('Y-m-d'),
            'area' => $event->area,
        ]));
        $response->assertRedirect(route('communityEvent.show', $event));
        $this->assertSame('Edited title', $event->fresh()->name);
    }

    public function test_a_non_editor_gets_404_on_update_even_with_an_invalid_payload(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $other = $this->joined($community);
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);

        $this->actingAs($other)->post(route('communityEvent.update', $event), ['name' => '', 'body' => ''])
            ->assertNotFound();
        $this->assertSame($event->name, $event->fresh()->name);
    }

    public function test_deleting_an_event_is_limited_to_author_and_admins_and_returns_to_the_community(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $other = $this->joined($community);
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);

        $this->actingAs($other)->get(route('communityEvent.delete.show', $event))->assertNotFound();
        $this->actingAs($other)->post(route('communityEvent.delete', $event))->assertNotFound();

        $this->actingAs($author)->get(route('communityEvent.delete.show', $event))
            ->assertOk()
            ->assertSee('id="page_communityEvent_deleteConfirm"', false);

        $this->actingAs($author)->post(route('communityEvent.delete', $event))
            ->assertRedirect(route('community.show', $community));
        $this->assertDatabaseMissing('community_events', ['id' => $event->getKey()]);
    }

    public function test_member_list_shows_participants_with_body_id(): void
    {
        $community = Community::factory()->create();
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey()]);
        $attendee = $this->joined($community);
        $event->participants()->attach($attendee);

        $response = $this->actingAs($this->joined($community))->get(route('communityEvent.member_list', $event));

        $response->assertOk();
        $response->assertSee('id="page_communityEvent_memberList"', false);
        $response->assertSee($attendee->name);
    }

    public function test_community_home_shows_the_recent_events_box_for_board_readers(): void
    {
        $community = Community::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        CommunityEvent::factory()->create(['community_id' => $community->getKey(), 'name' => 'Box event']);

        $this->actingAs($this->joined($community))->get(route('community.show', $community))
            ->assertOk()
            ->assertSee('Box event')
            ->assertSee(route('communityEvent.index', $community), false);

        $this->actingAs(Member::factory()->create())->get(route('community.show', $community))
            ->assertOk()
            ->assertDontSee('Box event');
    }
}
