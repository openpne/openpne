<?php

namespace Tests\Feature\GroupEvent\Classic;

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

    public function test_comment_only_saves_a_comment_without_touching_the_roster(): void
    {
        $group = Group::factory()->create();
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey()]);
        $member = $this->joined($group);

        $response = $this->actingAs($member)->post(route('group.events.comment.store', $event), [
            'body' => 'Just a note.',
            'comment' => 'Add a comment only',
        ]);

        $response->assertRedirect(route('group.events.show', $event));
        $this->assertDatabaseHas('group_event_comments', ['group_event_id' => $event->getKey(), 'body' => 'Just a note.']);
        $this->assertSame(0, $event->fresh()->participantCount());
    }

    public function test_participate_joins_the_roster_and_saves_the_comment(): void
    {
        $group = Group::factory()->create();
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey()]);
        $member = $this->joined($group);

        $response = $this->actingAs($member)->post(route('group.events.comment.store', $event), [
            'body' => 'Count me in!',
            'participate' => 'Participate in this event',
        ]);

        $response->assertRedirect(route('group.events.show', $event));
        $this->assertTrue($event->fresh()->isParticipant($member));
        $this->assertSame(1, $event->fresh()->participantCount());
        $this->assertDatabaseHas('group_event_comments', ['group_event_id' => $event->getKey(), 'body' => 'Count me in!']);
    }

    public function test_cancel_leaves_the_roster_and_saves_the_comment(): void
    {
        $group = Group::factory()->create();
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey()]);
        $member = $this->joined($group);
        $event->participants()->attach($member);

        $this->actingAs($member)->post(route('group.events.comment.store', $event), [
            'body' => 'Sorry, can\'t make it.',
            'cancel' => 'Cancel to join',
        ])->assertRedirect(route('group.events.show', $event));

        $this->assertFalse($event->fresh()->isParticipant($member));
        $this->assertSame(0, $event->fresh()->participantCount());
        $this->assertDatabaseCount('group_event_comments', 1);
    }

    public function test_a_comment_requires_a_body(): void
    {
        $group = Group::factory()->create();
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey()]);
        $member = $this->joined($group);

        $this->actingAs($member)->post(route('group.events.comment.store', $event), [
            'participate' => 'Participate in this event',
        ])->assertSessionHasErrors('body');

        // No silent participation when the comment is invalid: validation precedes the toggle.
        $this->assertSame(0, $event->fresh()->participantCount());
        $this->assertDatabaseCount('group_event_comments', 0);
    }

    public function test_a_non_member_is_404_and_posts_nothing(): void
    {
        $group = Group::factory()->create();
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey()]);
        $stranger = Member::factory()->create();

        $this->actingAs($stranger)->post(route('group.events.comment.store', $event), [
            'body' => 'Sneaking in',
            'participate' => 'Participate in this event',
        ])->assertNotFound();

        $this->assertDatabaseCount('group_event_comments', 0);
        $this->assertSame(0, $event->fresh()->participantCount());
    }

    public function test_joining_a_full_event_is_refused_and_rolls_back_the_comment(): void
    {
        $group = Group::factory()->create();
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'capacity' => 1]);
        $event->participants()->attach($this->joined($group));
        $latecomer = $this->joined($group);

        $response = $this->actingAs($latecomer)->post(route('group.events.comment.store', $event), [
            'body' => 'Room for one more?',
            'participate' => 'Participate in this event',
        ]);

        $response->assertRedirect(route('group.events.show', $event));
        $response->assertSessionHas('error');
        // The guard aborts the whole transaction: neither the join nor the comment persists.
        $this->assertSame(1, $event->fresh()->participantCount());
        $this->assertDatabaseCount('group_event_comments', 0);
    }

    public function test_a_closed_event_refuses_participation_but_still_accepts_a_comment_only(): void
    {
        $group = Group::factory()->create();
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'open_date' => now()->subDays(2)]);
        $member = $this->joined($group);

        // Participating is blocked once closed; the button is hidden, but a crafted POST is caught
        // by the roster guard and flashes an error rather than 404ing.
        $this->actingAs($member)->post(route('group.events.comment.store', $event), [
            'body' => 'Late join attempt',
            'participate' => 'Participate in this event',
        ])->assertSessionHas('error');
        $this->assertSame(0, $event->fresh()->participantCount());
        $this->assertDatabaseCount('group_event_comments', 0);

        // Commenting is still allowed after the event closes.
        $this->actingAs($member)->post(route('group.events.comment.store', $event), [
            'body' => 'Thanks, it was fun!',
            'comment' => 'Add a comment only',
        ])->assertRedirect(route('group.events.show', $event));
        $this->assertDatabaseCount('group_event_comments', 1);
    }

    public function test_comments_are_numbered_and_lift_the_event_on_the_board(): void
    {
        $group = Group::factory()->create();
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey()]);
        $member = $this->joined($group);

        $this->actingAs($member)->post(route('group.events.comment.store', $event), ['body' => 'first', 'comment' => '1']);
        $this->actingAs($member)->post(route('group.events.comment.store', $event), ['body' => 'second', 'comment' => '1']);

        $numbers = GroupEventComment::where('group_event_id', $event->getKey())->orderBy('id')->pluck('number');
        $this->assertSame([1, 2], $numbers->all());
        $this->assertTrue($event->fresh()->event_updated_at->greaterThan(now()->subMinute()));
    }

    public function test_deleting_a_comment_is_limited_to_its_author_and_event_editors(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $other = $this->joined($group);
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey()]);
        $comment = GroupEventComment::factory()->create([
            'group_event_id' => $event->getKey(),
            'member_id' => $author->getKey(),
        ]);

        $this->actingAs($other)->get(route('group.events.comment.delete.show', $comment))->assertNotFound();
        $this->actingAs($author)->get(route('group.events.comment.delete.show', $comment))
            ->assertOk()
            ->assertSee('id="page_communityEventComment_deleteConfirm"', false);

        $this->actingAs($author)->post(route('group.events.comment.delete', $comment))
            ->assertRedirect(route('group.events.show', $event));
        $this->assertDatabaseMissing('group_event_comments', ['id' => $comment->getKey()]);
    }
}
