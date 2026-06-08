<?php

namespace Tests\Feature\CommunityEvent\Classic;

use App\Features\Community\CommunityRole;
use App\Models\Community;
use App\Models\CommunityEvent;
use App\Models\CommunityEventComment;
use App\Models\CommunityMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The merged RSVP/comment endpoint: OpenPNE 3 posts participation through comment-create. The
 * participate/cancel button toggles the roster and saves the (required) comment; the "comment only"
 * button just saves it. A roster guard (closed / expired / full) aborts both.
 */
class CommunityEventCommentRoutesTest extends TestCase
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

    public function test_comment_only_saves_a_comment_without_touching_the_roster(): void
    {
        $community = Community::factory()->create();
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey()]);
        $member = $this->joined($community);

        $response = $this->actingAs($member)->post(route('communityEvent.comment.store', $event), [
            'body' => 'Just a note.',
            'comment' => 'Add a comment only',
        ]);

        $response->assertRedirect(route('communityEvent.show', $event));
        $this->assertDatabaseHas('community_event_comments', ['community_event_id' => $event->getKey(), 'body' => 'Just a note.']);
        $this->assertSame(0, $event->fresh()->participantCount());
    }

    public function test_participate_joins_the_roster_and_saves_the_comment(): void
    {
        $community = Community::factory()->create();
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey()]);
        $member = $this->joined($community);

        $response = $this->actingAs($member)->post(route('communityEvent.comment.store', $event), [
            'body' => 'Count me in!',
            'participate' => 'Participate in this event',
        ]);

        $response->assertRedirect(route('communityEvent.show', $event));
        $this->assertTrue($event->fresh()->isParticipant($member));
        $this->assertSame(1, $event->fresh()->participantCount());
        $this->assertDatabaseHas('community_event_comments', ['community_event_id' => $event->getKey(), 'body' => 'Count me in!']);
    }

    public function test_cancel_leaves_the_roster_and_saves_the_comment(): void
    {
        $community = Community::factory()->create();
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey()]);
        $member = $this->joined($community);
        $event->participants()->attach($member);

        $this->actingAs($member)->post(route('communityEvent.comment.store', $event), [
            'body' => 'Sorry, can\'t make it.',
            'cancel' => 'Cancel to join',
        ])->assertRedirect(route('communityEvent.show', $event));

        $this->assertFalse($event->fresh()->isParticipant($member));
        $this->assertSame(0, $event->fresh()->participantCount());
        $this->assertDatabaseCount('community_event_comments', 1);
    }

    public function test_a_comment_requires_a_body(): void
    {
        $community = Community::factory()->create();
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey()]);
        $member = $this->joined($community);

        $this->actingAs($member)->post(route('communityEvent.comment.store', $event), [
            'participate' => 'Participate in this event',
        ])->assertSessionHasErrors('body');

        // No silent participation when the comment is invalid: validation precedes the toggle.
        $this->assertSame(0, $event->fresh()->participantCount());
        $this->assertDatabaseCount('community_event_comments', 0);
    }

    public function test_a_non_member_is_404_and_posts_nothing(): void
    {
        $community = Community::factory()->create();
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey()]);
        $stranger = Member::factory()->create();

        $this->actingAs($stranger)->post(route('communityEvent.comment.store', $event), [
            'body' => 'Sneaking in',
            'participate' => 'Participate in this event',
        ])->assertNotFound();

        $this->assertDatabaseCount('community_event_comments', 0);
        $this->assertSame(0, $event->fresh()->participantCount());
    }

    public function test_joining_a_full_event_is_refused_and_rolls_back_the_comment(): void
    {
        $community = Community::factory()->create();
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey(), 'capacity' => 1]);
        $event->participants()->attach($this->joined($community));
        $latecomer = $this->joined($community);

        $response = $this->actingAs($latecomer)->post(route('communityEvent.comment.store', $event), [
            'body' => 'Room for one more?',
            'participate' => 'Participate in this event',
        ]);

        $response->assertRedirect(route('communityEvent.show', $event));
        $response->assertSessionHas('error');
        // The guard aborts the whole transaction: neither the join nor the comment persists.
        $this->assertSame(1, $event->fresh()->participantCount());
        $this->assertDatabaseCount('community_event_comments', 0);
    }

    public function test_a_closed_event_refuses_participation_but_still_accepts_a_comment_only(): void
    {
        $community = Community::factory()->create();
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey(), 'open_date' => now()->subDays(2)]);
        $member = $this->joined($community);

        // Participating is blocked once closed; the button is hidden, but a crafted POST is caught
        // by the roster guard and flashes an error rather than 404ing.
        $this->actingAs($member)->post(route('communityEvent.comment.store', $event), [
            'body' => 'Late join attempt',
            'participate' => 'Participate in this event',
        ])->assertSessionHas('error');
        $this->assertSame(0, $event->fresh()->participantCount());
        $this->assertDatabaseCount('community_event_comments', 0);

        // Commenting is still allowed after the event closes.
        $this->actingAs($member)->post(route('communityEvent.comment.store', $event), [
            'body' => 'Thanks, it was fun!',
            'comment' => 'Add a comment only',
        ])->assertRedirect(route('communityEvent.show', $event));
        $this->assertDatabaseCount('community_event_comments', 1);
    }

    public function test_comments_are_numbered_and_lift_the_event_on_the_board(): void
    {
        $community = Community::factory()->create();
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey()]);
        $member = $this->joined($community);

        $this->actingAs($member)->post(route('communityEvent.comment.store', $event), ['body' => 'first', 'comment' => '1']);
        $this->actingAs($member)->post(route('communityEvent.comment.store', $event), ['body' => 'second', 'comment' => '1']);

        $numbers = CommunityEventComment::where('community_event_id', $event->getKey())->orderBy('id')->pluck('number');
        $this->assertSame([1, 2], $numbers->all());
        $this->assertTrue($event->fresh()->event_updated_at->greaterThan(now()->subMinute()));
    }

    public function test_deleting_a_comment_is_limited_to_its_author_and_event_editors(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $other = $this->joined($community);
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey()]);
        $comment = CommunityEventComment::factory()->create([
            'community_event_id' => $event->getKey(),
            'member_id' => $author->getKey(),
        ]);

        $this->actingAs($other)->get(route('communityEvent.comment.delete.show', $comment))->assertNotFound();
        $this->actingAs($author)->get(route('communityEvent.comment.delete.show', $comment))
            ->assertOk()
            ->assertSee('id="page_communityEventComment_deleteConfirm"', false);

        $this->actingAs($author)->post(route('communityEvent.comment.delete', $comment))
            ->assertRedirect(route('communityEvent.show', $event));
        $this->assertDatabaseMissing('community_event_comments', ['id' => $comment->getKey()]);
    }
}
