<?php

namespace Tests\Feature\CommunityEvent;

use App\Models\Community;
use App\Models\CommunityEvent;
use App\Models\CommunityEventComment;
use App\Models\CommunityEventMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunityEventModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduling_columns_cast_to_datetimes_and_integer(): void
    {
        $event = CommunityEvent::factory()->create([
            'open_date' => '2026-07-01 00:00:00',
            'application_deadline' => '2026-06-25 00:00:00',
            'event_updated_at' => '2026-06-20 09:00:00',
            'capacity' => 30,
        ]);

        $event->refresh();
        $this->assertTrue($event->open_date->equalTo('2026-07-01 00:00:00'));
        $this->assertTrue($event->application_deadline->equalTo('2026-06-25 00:00:00'));
        $this->assertTrue($event->event_updated_at->equalTo('2026-06-20 09:00:00'));
        $this->assertSame(30, $event->capacity);
    }

    public function test_relations_resolve(): void
    {
        $community = Community::factory()->create();
        $author = Member::factory()->create();
        $event = CommunityEvent::factory()->create([
            'community_id' => $community->getKey(),
            'member_id' => $author->getKey(),
        ]);
        $comment = CommunityEventComment::factory()->create([
            'community_event_id' => $event->getKey(),
            'member_id' => $author->getKey(),
        ]);

        $this->assertTrue($event->community->is($community));
        $this->assertTrue($event->member->is($author));
        $this->assertTrue($event->comments->first()->is($comment));
        $this->assertTrue($comment->event->is($event));
        $this->assertTrue($comment->member->is($author));
        $this->assertTrue($community->events->first()->is($event));
    }

    public function test_a_deleted_author_leaves_the_event_and_comment_intact(): void
    {
        $author = Member::factory()->create();
        $event = CommunityEvent::factory()->create(['member_id' => $author->getKey()]);
        $comment = CommunityEventComment::factory()->create([
            'community_event_id' => $event->getKey(),
            'member_id' => $author->getKey(),
        ]);

        $author->delete();

        $this->assertNull($event->refresh()->member_id);
        $this->assertNull($comment->refresh()->member_id);
    }

    public function test_deleting_an_event_cascades_to_comments_and_participants(): void
    {
        $event = CommunityEvent::factory()->create();
        CommunityEventComment::factory()->create(['community_event_id' => $event->getKey()]);
        CommunityEventMember::factory()->create(['community_event_id' => $event->getKey()]);

        $event->delete();

        $this->assertSame(0, CommunityEventComment::query()->count());
        $this->assertSame(0, CommunityEventMember::query()->count());
    }

    public function test_participants_pivot_tracks_rsvp(): void
    {
        $event = CommunityEvent::factory()->create();
        $member = Member::factory()->create();

        $this->assertFalse($event->isParticipant($member));
        $this->assertSame(0, $event->participantCount());

        $event->participants()->attach($member);

        $this->assertTrue($event->isParticipant($member));
        $this->assertSame(1, $event->participantCount());
    }

    public function test_is_closed_after_the_day_following_open_date(): void
    {
        $past = CommunityEvent::factory()->create(['open_date' => now()->subDays(2)]);
        $upcoming = CommunityEvent::factory()->create(['open_date' => now()->addDays(2)]);

        $this->assertTrue($past->isClosed());
        $this->assertFalse($upcoming->isClosed());
    }

    public function test_is_expired_only_when_a_passed_deadline_is_set(): void
    {
        $noDeadline = CommunityEvent::factory()->create(['application_deadline' => null]);
        $passed = CommunityEvent::factory()->create(['application_deadline' => now()->subDays(2)]);
        $future = CommunityEvent::factory()->create(['application_deadline' => now()->addDays(2)]);

        $this->assertFalse($noDeadline->isExpired());
        $this->assertTrue($passed->isExpired());
        $this->assertFalse($future->isExpired());
    }

    public function test_is_full_only_when_capacity_is_reached(): void
    {
        $unlimited = CommunityEvent::factory()->create(['capacity' => null]);
        $unlimited->participants()->attach(Member::factory()->create());
        $this->assertFalse($unlimited->isFull());

        $capped = CommunityEvent::factory()->create(['capacity' => 2]);
        $capped->participants()->attach(Member::factory()->create());
        $this->assertFalse($capped->isFull());

        $capped->participants()->attach(Member::factory()->create());
        $this->assertTrue($capped->isFull());
    }
}
