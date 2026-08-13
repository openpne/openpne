<?php

namespace Tests\Feature\GroupEvent\Modern;

use App\Features\Group\GroupRole;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroupEventCommentRoutesTest extends TestCase
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

    public function test_guests_are_redirected_to_login(): void
    {
        $group = Group::factory()->create();
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey()]);
        $comment = GroupEventComment::factory()->create(['group_event_id' => $event->getKey(), 'number' => 1]);

        $this->post(route('group.events.comment.store', $event))->assertRedirect('/login');
        $this->post(route('group.events.comment.delete', $comment))->assertRedirect('/login');
    }

    public function test_participate_button_joins_the_roster_and_saves_the_comment(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey()]);

        // No `comment` field → OpenPNE 3 toggles the roster (here: join).
        $this->actingAs($member)
            ->post(route('group.events.comment.store', $event), ['body' => 'Count me in'])
            ->assertRedirect(route('group.events.show', $event));

        $this->assertDatabaseHas('group_event_members', [
            'group_event_id' => $event->getKey(),
            'member_id' => $member->getKey(),
        ]);
        $this->assertDatabaseHas('group_event_comments', [
            'group_event_id' => $event->getKey(),
            'member_id' => $member->getKey(),
            'body' => 'Count me in',
        ]);
    }

    public function test_participate_button_leaves_the_roster_when_already_joined(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey()]);
        $event->participants()->attach($member);

        $this->actingAs($member)
            ->post(route('group.events.comment.store', $event), ['body' => 'Cannot make it after all'])
            ->assertRedirect(route('group.events.show', $event));

        $this->assertDatabaseMissing('group_event_members', [
            'group_event_id' => $event->getKey(),
            'member_id' => $member->getKey(),
        ]);
    }

    public function test_comment_only_button_saves_the_comment_without_touching_the_roster(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey()]);

        // `comment` field present → comment only, no roster toggle.
        $this->actingAs($member)
            ->post(route('group.events.comment.store', $event), ['body' => 'Just a question', 'comment' => '1'])
            ->assertRedirect(route('group.events.show', $event));

        $this->assertDatabaseHas('group_event_comments', [
            'group_event_id' => $event->getKey(),
            'body' => 'Just a question',
        ]);
        $this->assertDatabaseMissing('group_event_members', [
            'group_event_id' => $event->getKey(),
            'member_id' => $member->getKey(),
        ]);
    }

    public function test_a_closed_event_rejects_the_rsvp_and_rolls_back_the_comment(): void
    {
        // A roster guard is an in-app error (flash), not a 404, and the comment is rolled back with it.
        $group = Group::factory()->create();
        $member = $this->joined($group);
        $event = GroupEvent::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
            'open_date' => now()->subWeek()->startOfDay(),
        ]);

        $this->actingAs($member)
            ->post(route('group.events.comment.store', $event), ['body' => 'Too late'])
            ->assertRedirect(route('group.events.show', $event))
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('group_event_members', [
            'group_event_id' => $event->getKey(),
            'member_id' => $member->getKey(),
        ]);
        $this->assertDatabaseCount('group_event_comments', 0);
    }

    public function test_a_full_event_rejects_a_new_participant(): void
    {
        $group = Group::factory()->create();
        $taken = $this->joined($group);
        $latecomer = $this->joined($group);
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'member_id' => $taken->getKey(), 'capacity' => 1]);
        $event->participants()->attach($taken);

        $this->actingAs($latecomer)
            ->post(route('group.events.comment.store', $event), ['body' => 'Any room?'])
            ->assertRedirect(route('group.events.show', $event))
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('group_event_members', [
            'group_event_id' => $event->getKey(),
            'member_id' => $latecomer->getKey(),
        ]);
    }

    public function test_a_non_member_cannot_comment_or_rsvp(): void
    {
        $group = Group::factory()->create();
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey()]);
        $stranger = Member::factory()->create();

        $this->actingAs($stranger)
            ->post(route('group.events.comment.store', $event), ['body' => 'intruding'])
            ->assertNotFound();
        $this->assertDatabaseCount('group_event_comments', 0);
    }

    public function test_modern_comment_delete_removes_the_comment_and_redirects_to_show(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey()]);
        $comment = GroupEventComment::factory()->create([
            'group_event_id' => $event->getKey(),
            'member_id' => $member->getKey(),
            'number' => 1,
        ]);

        $this->actingAs($member)
            ->post(route('group.events.comment.delete', $comment))
            ->assertRedirect(route('group.events.show', $event));

        $this->assertDatabaseMissing('group_event_comments', ['id' => $comment->getKey()]);
    }

    public function test_modern_comment_delete_returns_404_for_an_unauthorized_member(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);
        $comment = GroupEventComment::factory()->create([
            'group_event_id' => $event->getKey(),
            'member_id' => $author->getKey(),
            'number' => 1,
        ]);
        $other = $this->joined($group);

        $this->actingAs($other)
            ->post(route('group.events.comment.delete', $comment))
            ->assertNotFound();
        $this->assertDatabaseHas('group_event_comments', ['id' => $comment->getKey()]);
    }
}
