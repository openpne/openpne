<?php

namespace Tests\Feature\GroupEvent\Actions;

use App\Features\Group\GroupRole;
use App\Features\GroupEvent\Actions\CreateEvent;
use App\Features\GroupEvent\Actions\CreateEventComment;
use App\Features\GroupEvent\Actions\DeleteEvent;
use App\Features\GroupEvent\Actions\DeleteEventComment;
use App\Features\GroupEvent\Actions\ToggleParticipation;
use App\Features\GroupEvent\Actions\UpdateEvent;
use App\Features\GroupEvent\Data\GroupEventFormData;
use App\Features\GroupEvent\Exceptions\GroupEventActionException;
use App\Features\GroupEvent\Exceptions\GroupEventActionFailure;
use App\Features\GroupTopic\TopicPostAuthority;
use App\Files\ImageEdit;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Models\GroupMember;
use App\Models\Member;
use App\Support\BodyFormat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EventActionsTest extends TestCase
{
    use RefreshDatabase;

    private function joined(Group $group, GroupRole $role): Member
    {
        $member = Member::factory()->create();
        GroupMember::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
            'role' => $role,
        ]);

        return $member;
    }

    private function formData(GroupEvent $event, array $overrides = []): GroupEventFormData
    {
        return new GroupEventFormData(
            name: $overrides['name'] ?? $event->name,
            body: $overrides['body'] ?? $event->body,
            open_date: $overrides['open_date'] ?? $event->open_date->format('Y-m-d'),
            open_date_comment: $overrides['open_date_comment'] ?? (string) $event->open_date_comment,
            area: $overrides['area'] ?? $event->area,
            application_deadline: $overrides['application_deadline'] ?? $event->application_deadline?->format('Y-m-d'),
            capacity: array_key_exists('capacity', $overrides) ? $overrides['capacity'] : $event->capacity,
            format: $overrides['format'] ?? null,
        );
    }

    private function assertFails(callable $run, GroupEventActionFailure $reason): void
    {
        try {
            $run();
            $this->fail("expected GroupEventActionException [{$reason->value}]");
        } catch (GroupEventActionException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    public function test_create_event_sets_the_author_and_activity_timestamp(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group, GroupRole::Member);

        $event = app(CreateEvent::class)($author, $group, new GroupEventFormData(
            name: 'Meetup',
            body: 'Come along.',
            open_date: now()->addWeek()->format('Y-m-d'),
            open_date_comment: '19:00-',
            area: 'Shibuya',
            application_deadline: null,
            capacity: null,
        ));

        $this->assertSame($group->getKey(), $event->group_id);
        $this->assertSame($author->getKey(), $event->member_id);
        $this->assertSame('Shibuya', $event->area);
        $this->assertNotNull($event->event_updated_at);
    }

    public function test_create_event_is_blocked_when_posting_is_admin_only(): void
    {
        $group = Group::factory()->create(['topic_post_authority' => TopicPostAuthority::AdminsOnly]);
        $member = $this->joined($group, GroupRole::Member);

        $this->assertFails(
            fn () => app(CreateEvent::class)($member, $group, new GroupEventFormData(
                'No', 'Nope.', now()->addWeek()->format('Y-m-d'), '', 'Nowhere', null, null,
            )),
            GroupEventActionFailure::CannotPost,
        );
    }

    public function test_update_event_bumps_event_updated_at_only_on_a_content_change(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group, GroupRole::Member);
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);
        DB::table('group_events')->where('id', $event->getKey())->update([
            'updated_at' => now()->subDay(),
            'event_updated_at' => now()->subDay(),
        ]);

        // No-op edit (same content) does not touch the timestamps.
        app(UpdateEvent::class)($author, $event->fresh(), $this->formData($event), ImageEdit::none());
        $this->assertTrue($event->fresh()->updated_at->lessThan(now()->subHour()));

        // A name change bumps both updated_at (board key) and event_updated_at.
        app(UpdateEvent::class)($author, $event->fresh(), $this->formData($event, ['name' => 'Edited']), ImageEdit::none());
        $fresh = $event->fresh();
        $this->assertSame('Edited', $fresh->name);
        $this->assertTrue($fresh->updated_at->greaterThan(now()->subMinute()));
        $this->assertTrue($fresh->event_updated_at->greaterThan(now()->subMinute()));
    }

    public function test_update_event_cannot_change_an_op3_rows_format(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group, GroupRole::Member);
        $event = GroupEvent::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $author->getKey(),
            'format' => BodyFormat::Op3,
        ]);

        app(UpdateEvent::class)($author, $event, $this->formData($event, ['format' => BodyFormat::Markdown]), ImageEdit::none());

        $this->assertSame(BodyFormat::Op3, $event->fresh()->format);
    }

    public function test_update_event_scheduling_only_change_bumps_updated_at_not_event_updated_at(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group, GroupRole::Member);
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);
        DB::table('group_events')->where('id', $event->getKey())->update([
            'updated_at' => now()->subDay(),
            'event_updated_at' => now()->subDay(),
        ]);

        // Changing only the capacity (not name/body) lifts the board (updated_at) but not the
        // content timestamp (event_updated_at).
        app(UpdateEvent::class)($author, $event->fresh(), $this->formData($event, ['capacity' => 50]), ImageEdit::none());

        $fresh = $event->fresh();
        $this->assertSame(50, $fresh->capacity);
        $this->assertTrue($fresh->updated_at->greaterThan(now()->subMinute()));
        $this->assertTrue($fresh->event_updated_at->lessThan(now()->subHour()));
    }

    public function test_update_event_is_blocked_for_a_non_author_non_admin(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group, GroupRole::Member);
        $other = $this->joined($group, GroupRole::Member);
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);

        $this->assertFails(
            fn () => app(UpdateEvent::class)($other, $event, $this->formData($event, ['name' => 'Hijack']), ImageEdit::none()),
            GroupEventActionFailure::CannotEdit,
        );
    }

    public function test_delete_event_removes_it_and_cascades_comments_and_participants(): void
    {
        $group = Group::factory()->create();
        $admin = $this->joined($group, GroupRole::Admin);
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'member_id' => $admin->getKey()]);
        GroupEventComment::factory()->create(['group_event_id' => $event->getKey()]);
        $event->participants()->attach($this->joined($group, GroupRole::Member));

        (new DeleteEvent)($admin, $event);

        $this->assertDatabaseMissing('group_events', ['id' => $event->getKey()]);
        $this->assertSame(0, GroupEventComment::query()->count());
        $this->assertSame(0, DB::table('group_event_members')->count());
    }

    public function test_comments_are_numbered_per_event_and_lift_both_timestamps(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group, GroupRole::Member);
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);
        DB::table('group_events')->where('id', $event->getKey())->update([
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
        $group = Group::factory()->create();
        $author = $this->joined($group, GroupRole::Member);
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);
        $stranger = Member::factory()->create();

        $this->assertFails(
            fn () => app(CreateEventComment::class)($stranger, $event, 'intruding'),
            GroupEventActionFailure::CannotComment,
        );
    }

    public function test_delete_comment_is_blocked_for_an_unrelated_member(): void
    {
        $group = Group::factory()->create();
        $commenter = $this->joined($group, GroupRole::Member);
        $other = $this->joined($group, GroupRole::Member);
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey()]);
        $comment = GroupEventComment::factory()->create([
            'group_event_id' => $event->getKey(),
            'member_id' => $commenter->getKey(),
        ]);

        $this->assertFails(
            fn () => (new DeleteEventComment)($other, $comment),
            GroupEventActionFailure::CannotDeleteComment,
        );

        (new DeleteEventComment)($commenter, $comment);
        $this->assertDatabaseMissing('group_event_comments', ['id' => $comment->getKey()]);
    }

    public function test_toggle_participation_joins_then_leaves(): void
    {
        $group = Group::factory()->create();
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey()]);
        $member = $this->joined($group, GroupRole::Member);

        $this->assertTrue(app(ToggleParticipation::class)($member, $event));
        $this->assertSame(1, $event->fresh()->participantCount());

        $this->assertFalse(app(ToggleParticipation::class)($member, $event));
        $this->assertSame(0, $event->fresh()->participantCount());
    }

    public function test_toggle_join_is_blocked_at_capacity(): void
    {
        $group = Group::factory()->create();
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'capacity' => 1]);
        $first = $this->joined($group, GroupRole::Member);
        $second = $this->joined($group, GroupRole::Member);

        app(ToggleParticipation::class)($first, $event);

        $this->assertFails(
            fn () => app(ToggleParticipation::class)($second, $event),
            GroupEventActionFailure::EventAtCapacity,
        );
        // A member already on the roster can still leave a full event.
        $this->assertFalse(app(ToggleParticipation::class)($first, $event));
    }

    public function test_toggle_is_blocked_in_both_directions_when_closed(): void
    {
        $group = Group::factory()->create();
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'open_date' => now()->subDays(2)]);
        $member = $this->joined($group, GroupRole::Member);

        $this->assertFails(fn () => app(ToggleParticipation::class)($member, $event), GroupEventActionFailure::EventClosed);

        // Even an existing participant cannot cancel once the event has closed.
        $event->participants()->attach($member);
        $this->assertFails(fn () => app(ToggleParticipation::class)($member, $event), GroupEventActionFailure::EventClosed);
    }

    public function test_toggle_is_blocked_when_the_deadline_has_passed(): void
    {
        $group = Group::factory()->create();
        $event = GroupEvent::factory()->create([
            'group_id' => $group->getKey(),
            'open_date' => now()->addDays(5),
            'application_deadline' => now()->subDays(2),
        ]);
        $member = $this->joined($group, GroupRole::Member);

        $this->assertFails(fn () => app(ToggleParticipation::class)($member, $event), GroupEventActionFailure::EventExpired);
    }

    public function test_toggle_participation_requires_membership(): void
    {
        $group = Group::factory()->create();
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey()]);
        $stranger = Member::factory()->create();

        $this->assertFails(
            fn () => app(ToggleParticipation::class)($stranger, $event),
            GroupEventActionFailure::NotMember,
        );
    }
}
