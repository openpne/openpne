<?php

namespace Tests\Feature\GroupEvent\Classic;

use App\Features\Group\GroupRole;
use App\Features\GroupTopic\TopicPostAuthority;
use App\Features\GroupTopic\TopicReadAccess;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GroupEventRoutesTest extends TestCase
{
    use RefreshDatabase;

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
        $group = Group::factory()->create();
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey()]);

        $this->get(route('group.events.index', $group))->assertRedirect('/login');
        $this->get(route('group.events.show', $event))->assertRedirect('/login');
        $this->post(route('group.events.store', $group))->assertRedirect('/login');
    }

    public function test_board_renders_with_body_id_and_most_recent_activity_first(): void
    {
        $group = Group::factory()->create();
        $stale = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'name' => 'Stale event']);
        $fresh = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'name' => 'Fresh event']);
        DB::table('group_events')->where('id', $stale->getKey())->update(['updated_at' => now()->subDays(3)]);

        $response = $this->actingAs($this->joined($group))->get(route('group.events.index', $group));

        $response->assertOk();
        $response->assertSee('id="page_communityEvent_listCommunity"', false);
        // Board order is updated_at DESC (activity), not open_date.
        $response->assertSeeInOrder(['Fresh event', 'Stale event']);
    }

    public function test_board_shows_comment_counts(): void
    {
        $group = Group::factory()->create();
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'name' => 'Counted']);
        GroupEventComment::factory()->count(2)->sequence(['number' => 1], ['number' => 2])
            ->create(['group_event_id' => $event->getKey()]);

        $response = $this->actingAs($this->joined($group))->get(route('group.events.index', $group));

        $response->assertOk();
        // listCommunitySuccess.php formats the label as sprintf('%s(%d)') — no space before the count.
        $response->assertSee('Counted(2)');
    }

    public function test_board_draws_the_openpne3_recent_list(): void
    {
        $group = Group::factory()->create();
        $author = Member::factory()->create(['name' => 'Evan']);
        $event = GroupEvent::factory()->create([
            'group_id' => $group->getKey(), 'name' => 'A meetup',
            'member_id' => $author->getKey(), 'open_date' => '2026-07-01',
        ]);
        DB::table('group_events')->where('id', $event->getKey())->update(['updated_at' => '2026-06-04 13:44:00']);

        $response = $this->actingAs($this->joined($group))
            ->withSession(['locale' => 'ja'])
            ->get(route('group.events.index', $group))
            ->assertOk();

        // One dl per event: the last-activity datetime in the dt, the "name(count)" link in the dd.
        // then the open date as the single trailing parenthetical (the author stays on the show page).
        $response->assertSee('<dt>2026年06月04日 13:44</dt>', false);
        $response->assertSee(
            '<dd><a href="'.route('group.events.show', $event).'">A meetup(0)</a></dd>',
            false,
        );
        // The pager brackets the list, as op_include_pager_navigation does above and below it.
        $this->assertSame(2, substr_count((string) $response->getContent(), 'class="pagerRelative"'));
    }

    public function test_members_only_board_is_hidden_from_non_members(): void
    {
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey()]);
        $stranger = Member::factory()->create();

        $this->actingAs($stranger)->get(route('group.events.index', $group))->assertNotFound();
        $this->actingAs($stranger)->get(route('group.events.show', $event))->assertNotFound();
        $this->actingAs($stranger)->get(route('group.events.member_list', $event))->assertNotFound();

        $this->actingAs($this->joined($group))->get(route('group.events.show', $event))->assertOk();
    }

    public function test_show_renders_event_with_body_id_and_scheduling_details(): void
    {
        $group = Group::factory()->create();
        $event = GroupEvent::factory()->create([
            'group_id' => $group->getKey(),
            'name' => 'Hello event',
            'body' => 'Come along.',
            'area' => 'Shibuya Hall',
            'capacity' => 30,
        ]);

        $response = $this->actingAs($this->joined($group))->get(route('group.events.show', $event));

        $response->assertOk();
        $response->assertSee('id="page_communityEvent_show"', false);
        $response->assertSee('Hello event');
        $response->assertSee('Come along.');
        $response->assertSee('Shibuya Hall');
        $response->assertSee('30');
    }

    public function test_show_autolinks_a_url_in_the_area(): void
    {
        // OpenPNE 3 runs the area through op_url_cmd, so an online-meeting URL becomes a link.
        $group = Group::factory()->create();
        $event = GroupEvent::factory()->create([
            'group_id' => $group->getKey(),
            'area' => 'Online: https://example.com/room',
        ]);

        $this->actingAs($this->joined($group))->get(route('group.events.show', $event))
            ->assertOk()
            ->assertSee('href="https://example.com/room"', false);
    }

    public function test_show_for_unknown_event_returns_404(): void
    {
        $this->actingAs(Member::factory()->create())->get('/events/999999')->assertNotFound();
    }

    public function test_a_non_numeric_literal_is_not_swallowed_by_the_event_wildcard(): void
    {
        // Both the canonical show and the OpenPNE 3 compat redirect are digit-constrained, so the
        // un-ported shared search URL resolves as neither an event id nor a redirect.
        $this->actingAs(Member::factory()->create())->get('/events/search')->assertNotFound();
        $this->actingAs(Member::factory()->create())->get('/communityEvent/search')->assertNotFound();
    }

    public function test_new_event_is_admin_only_when_posting_is_restricted(): void
    {
        $group = Group::factory()->create(['topic_post_authority' => TopicPostAuthority::AdminsOnly]);
        $member = $this->joined($group, GroupRole::Member);
        $admin = $this->joined($group, GroupRole::Admin);

        $this->actingAs($member)->get(route('group.events.new', $group))->assertNotFound();
        $this->actingAs($member)->post(route('group.events.store', $group), $this->eventPayload())->assertNotFound();

        $this->actingAs($admin)->get(route('group.events.new', $group))
            ->assertOk()
            ->assertSee('id="page_communityEvent_new"', false);
    }

    public function test_a_member_posts_an_event_and_is_redirected_to_it(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);

        $response = $this->actingAs($member)->post(route('group.events.store', $group), $this->eventPayload([
            'name' => 'Welcome party',
            'capacity' => 20,
        ]));

        $event = GroupEvent::where('name', 'Welcome party')->firstOrFail();
        $response->assertRedirect(route('group.events.show', $event));
        $this->assertSame($member->getKey(), $event->member_id);
        $this->assertSame($group->getKey(), $event->group_id);
        $this->assertSame('Yoyogi Park', $event->area);
        $this->assertSame(20, $event->capacity);
    }

    public function test_an_unauthorized_poster_gets_404_even_with_an_invalid_payload(): void
    {
        $group = Group::factory()->create();
        $stranger = Member::factory()->create();

        $this->actingAs($stranger)->post(route('group.events.store', $group), ['name' => '', 'body' => ''])
            ->assertNotFound();
        $this->assertDatabaseCount('group_events', 0);
    }

    public function test_editing_an_event_is_limited_to_its_author_and_admins(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $admin = $this->joined($group, GroupRole::Admin);
        $other = $this->joined($group);
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);

        $this->actingAs($other)->get(route('group.events.edit', $event))->assertNotFound();
        $this->actingAs($admin)->get(route('group.events.edit', $event))->assertOk()
            ->assertSee('id="page_communityEvent_edit"', false);

        $response = $this->actingAs($author)->post(route('group.events.update', $event), $this->eventPayload([
            'name' => 'Edited title',
            'body' => $event->body,
            'open_date' => $event->open_date->format('Y-m-d'),
            'area' => $event->area,
        ]));
        $response->assertRedirect(route('group.events.show', $event));
        $this->assertSame('Edited title', $event->fresh()->name);
    }

    public function test_a_non_editor_gets_404_on_update_even_with_an_invalid_payload(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $other = $this->joined($group);
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);

        $this->actingAs($other)->post(route('group.events.update', $event), ['name' => '', 'body' => ''])
            ->assertNotFound();
        $this->assertSame($event->name, $event->fresh()->name);
    }

    public function test_deleting_an_event_is_limited_to_author_and_admins_and_returns_to_the_community(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $other = $this->joined($group);
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);

        $this->actingAs($other)->get(route('group.events.delete.show', $event))->assertNotFound();
        $this->actingAs($other)->post(route('group.events.delete', $event))->assertNotFound();

        $this->actingAs($author)->get(route('group.events.delete.show', $event))
            ->assertOk()
            ->assertSee('id="page_communityEvent_deleteConfirm"', false);

        $this->actingAs($author)->post(route('group.events.delete', $event))
            ->assertRedirect(route('group.show', $group));
        $this->assertDatabaseMissing('group_events', ['id' => $event->getKey()]);
    }

    public function test_member_list_shows_participants_with_body_id(): void
    {
        $group = Group::factory()->create();
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey()]);
        $attendee = $this->joined($group);
        $event->participants()->attach($attendee);

        $response = $this->actingAs($this->joined($group))->get(route('group.events.member_list', $event));

        $response->assertOk();
        $response->assertSee('id="page_communityEvent_memberList"', false);
        $response->assertSee('<tr class="photo">', false);
        $response->assertSee('>'.$attendee->name.' (0)</a>', false);
        $response->assertSee('class="pagerRelative"', false);
    }

    public function test_community_home_shows_the_recent_events_box_for_board_readers(): void
    {
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        GroupEvent::factory()->create(['group_id' => $group->getKey(), 'name' => 'Box event']);

        $this->actingAs($this->joined($group))->get(route('group.show', $group))
            ->assertOk()
            ->assertSee('Box event')
            ->assertSee(route('group.events.index', $group), false);

        $this->actingAs(Member::factory()->create())->get(route('group.show', $group))
            ->assertOk()
            ->assertDontSee('Box event');
    }
}
