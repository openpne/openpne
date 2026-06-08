<?php

namespace Tests\Feature\CommunityEvent\Actions;

use App\Features\Community\CommunityRole;
use App\Features\CommunityEvent\Actions\CreateEvent;
use App\Features\CommunityEvent\Actions\CreateEventComment;
use App\Features\CommunityEvent\Actions\DeleteEvent;
use App\Features\CommunityEvent\Actions\DeleteEventComment;
use App\Features\CommunityEvent\Actions\ToggleParticipation;
use App\Features\CommunityEvent\Actions\UpdateEvent;
use App\Features\CommunityEvent\Data\CommunityEventFormData;
use App\Features\CommunityEvent\Exceptions\CommunityEventActionException;
use App\Features\CommunityEvent\Exceptions\CommunityEventActionFailure;
use App\Features\CommunityTopic\TopicPostAuthority;
use App\Models\Community;
use App\Models\CommunityEvent;
use App\Models\CommunityEventComment;
use App\Models\CommunityMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EventActionsTest extends TestCase
{
    use RefreshDatabase;

    private function joined(Community $community, CommunityRole $role): Member
    {
        $member = Member::factory()->create();
        CommunityMember::factory()->create([
            'community_id' => $community->getKey(),
            'member_id' => $member->getKey(),
            'role' => $role,
        ]);

        return $member;
    }

    private function formData(CommunityEvent $event, array $overrides = []): CommunityEventFormData
    {
        return new CommunityEventFormData(
            name: $overrides['name'] ?? $event->name,
            body: $overrides['body'] ?? $event->body,
            open_date: $overrides['open_date'] ?? $event->open_date->format('Y-m-d'),
            open_date_comment: $overrides['open_date_comment'] ?? (string) $event->open_date_comment,
            area: $overrides['area'] ?? $event->area,
            application_deadline: $overrides['application_deadline'] ?? $event->application_deadline?->format('Y-m-d'),
            capacity: array_key_exists('capacity', $overrides) ? $overrides['capacity'] : $event->capacity,
        );
    }

    private function assertFails(callable $run, CommunityEventActionFailure $reason): void
    {
        try {
            $run();
            $this->fail("expected CommunityEventActionException [{$reason->value}]");
        } catch (CommunityEventActionException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    public function test_create_event_sets_the_author_and_activity_timestamp(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community, CommunityRole::Member);

        $event = app(CreateEvent::class)($author, $community, new CommunityEventFormData(
            name: 'Meetup',
            body: 'Come along.',
            open_date: now()->addWeek()->format('Y-m-d'),
            open_date_comment: '19:00-',
            area: 'Shibuya',
            application_deadline: null,
            capacity: null,
        ));

        $this->assertSame($community->getKey(), $event->community_id);
        $this->assertSame($author->getKey(), $event->member_id);
        $this->assertSame('Shibuya', $event->area);
        $this->assertNotNull($event->event_updated_at);
    }

    public function test_create_event_is_blocked_when_posting_is_admin_only(): void
    {
        $community = Community::factory()->create(['topic_post_authority' => TopicPostAuthority::AdminsOnly]);
        $member = $this->joined($community, CommunityRole::Member);

        $this->assertFails(
            fn () => app(CreateEvent::class)($member, $community, new CommunityEventFormData(
                'No', 'Nope.', now()->addWeek()->format('Y-m-d'), '', 'Nowhere', null, null,
            )),
            CommunityEventActionFailure::CannotPost,
        );
    }

    public function test_update_event_bumps_event_updated_at_only_on_a_content_change(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community, CommunityRole::Member);
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);
        DB::table('community_events')->where('id', $event->getKey())->update([
            'updated_at' => now()->subDay(),
            'event_updated_at' => now()->subDay(),
        ]);

        // No-op edit (same content) does not touch the timestamps.
        app(UpdateEvent::class)($author, $event->fresh(), $this->formData($event));
        $this->assertTrue($event->fresh()->updated_at->lessThan(now()->subHour()));

        // A name change bumps both updated_at (board key) and event_updated_at.
        app(UpdateEvent::class)($author, $event->fresh(), $this->formData($event, ['name' => 'Edited']));
        $fresh = $event->fresh();
        $this->assertSame('Edited', $fresh->name);
        $this->assertTrue($fresh->updated_at->greaterThan(now()->subMinute()));
        $this->assertTrue($fresh->event_updated_at->greaterThan(now()->subMinute()));
    }

    public function test_update_event_scheduling_only_change_bumps_updated_at_not_event_updated_at(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community, CommunityRole::Member);
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);
        DB::table('community_events')->where('id', $event->getKey())->update([
            'updated_at' => now()->subDay(),
            'event_updated_at' => now()->subDay(),
        ]);

        // Changing only the capacity (not name/body) lifts the board (updated_at) but not the
        // content timestamp (event_updated_at), matching OpenPNE 3 isEventModified.
        app(UpdateEvent::class)($author, $event->fresh(), $this->formData($event, ['capacity' => 50]));

        $fresh = $event->fresh();
        $this->assertSame(50, $fresh->capacity);
        $this->assertTrue($fresh->updated_at->greaterThan(now()->subMinute()));
        $this->assertTrue($fresh->event_updated_at->lessThan(now()->subHour()));
    }

    public function test_update_event_is_blocked_for_a_non_author_non_admin(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community, CommunityRole::Member);
        $other = $this->joined($community, CommunityRole::Member);
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);

        $this->assertFails(
            fn () => app(UpdateEvent::class)($other, $event, $this->formData($event, ['name' => 'Hijack'])),
            CommunityEventActionFailure::CannotEdit,
        );
    }

    public function test_delete_event_removes_it_and_cascades_comments_and_participants(): void
    {
        $community = Community::factory()->create();
        $admin = $this->joined($community, CommunityRole::Admin);
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey(), 'member_id' => $admin->getKey()]);
        CommunityEventComment::factory()->create(['community_event_id' => $event->getKey()]);
        $event->participants()->attach($this->joined($community, CommunityRole::Member));

        (new DeleteEvent)($admin, $event);

        $this->assertDatabaseMissing('community_events', ['id' => $event->getKey()]);
        $this->assertSame(0, CommunityEventComment::query()->count());
        $this->assertSame(0, DB::table('community_event_members')->count());
    }

    public function test_comments_are_numbered_per_event_and_lift_both_timestamps(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community, CommunityRole::Member);
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);
        DB::table('community_events')->where('id', $event->getKey())->update([
            'updated_at' => now()->subDay(),
            'event_updated_at' => now()->subDay(),
        ]);

        $first = app(CreateEventComment::class)($author, $event, 'one');
        $second = app(CreateEventComment::class)($author, $event, 'two');
        $third = app(CreateEventComment::class)($author, $event, 'three');

        $this->assertSame([1, 2, 3], [$first->number, $second->number, $third->number]);
        $fresh = $event->fresh();
        $this->assertTrue($fresh->updated_at->greaterThan(now()->subMinute()));
        $this->assertTrue($fresh->event_updated_at->greaterThan(now()->subMinute()));
    }

    public function test_commenting_is_blocked_for_a_non_member(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community, CommunityRole::Member);
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);
        $stranger = Member::factory()->create();

        $this->assertFails(
            fn () => app(CreateEventComment::class)($stranger, $event, 'intruding'),
            CommunityEventActionFailure::CannotComment,
        );
    }

    public function test_delete_comment_is_blocked_for_an_unrelated_member(): void
    {
        $community = Community::factory()->create();
        $commenter = $this->joined($community, CommunityRole::Member);
        $other = $this->joined($community, CommunityRole::Member);
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey()]);
        $comment = CommunityEventComment::factory()->create([
            'community_event_id' => $event->getKey(),
            'member_id' => $commenter->getKey(),
        ]);

        $this->assertFails(
            fn () => (new DeleteEventComment)($other, $comment),
            CommunityEventActionFailure::CannotDeleteComment,
        );

        (new DeleteEventComment)($commenter, $comment);
        $this->assertDatabaseMissing('community_event_comments', ['id' => $comment->getKey()]);
    }

    public function test_toggle_participation_joins_then_leaves(): void
    {
        $community = Community::factory()->create();
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey()]);
        $member = $this->joined($community, CommunityRole::Member);

        $this->assertTrue(app(ToggleParticipation::class)($member, $event));
        $this->assertSame(1, $event->fresh()->participantCount());

        $this->assertFalse(app(ToggleParticipation::class)($member, $event));
        $this->assertSame(0, $event->fresh()->participantCount());
    }

    public function test_toggle_join_is_blocked_at_capacity(): void
    {
        $community = Community::factory()->create();
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey(), 'capacity' => 1]);
        $first = $this->joined($community, CommunityRole::Member);
        $second = $this->joined($community, CommunityRole::Member);

        app(ToggleParticipation::class)($first, $event);

        $this->assertFails(
            fn () => app(ToggleParticipation::class)($second, $event),
            CommunityEventActionFailure::EventAtCapacity,
        );
        // A member already on the roster can still leave a full event.
        $this->assertFalse(app(ToggleParticipation::class)($first, $event));
    }

    public function test_toggle_is_blocked_in_both_directions_when_closed(): void
    {
        $community = Community::factory()->create();
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey(), 'open_date' => now()->subDays(2)]);
        $member = $this->joined($community, CommunityRole::Member);

        $this->assertFails(fn () => app(ToggleParticipation::class)($member, $event), CommunityEventActionFailure::EventClosed);

        // Even an existing participant cannot cancel once the event has closed.
        $event->participants()->attach($member);
        $this->assertFails(fn () => app(ToggleParticipation::class)($member, $event), CommunityEventActionFailure::EventClosed);
    }

    public function test_toggle_is_blocked_when_the_deadline_has_passed(): void
    {
        $community = Community::factory()->create();
        $event = CommunityEvent::factory()->create([
            'community_id' => $community->getKey(),
            'open_date' => now()->addDays(5),
            'application_deadline' => now()->subDays(2),
        ]);
        $member = $this->joined($community, CommunityRole::Member);

        $this->assertFails(fn () => app(ToggleParticipation::class)($member, $event), CommunityEventActionFailure::EventExpired);
    }

    public function test_toggle_participation_requires_membership(): void
    {
        $community = Community::factory()->create();
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey()]);
        $stranger = Member::factory()->create();

        $this->assertFails(
            fn () => app(ToggleParticipation::class)($stranger, $event),
            CommunityEventActionFailure::NotMember,
        );
    }
}
