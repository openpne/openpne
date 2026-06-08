<?php

namespace Tests\Feature\CommunityEvent\Requests;

use App\Features\Community\CommunityRole;
use App\Http\Requests\CommunityEvent\StoreEventCommentRequest;
use App\Http\Requests\CommunityEvent\StoreEventRequest;
use App\Http\Requests\CommunityEvent\UpdateEventRequest;
use App\Models\Community;
use App\Models\CommunityEvent;
use App\Models\CommunityMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Drives the event form requests through throwaway routes (the real routes land with the Classic
 * adapter), to pin the OpenPNE 3 validation rules and the 404-on-refusal authorization.
 */
class CommunityEventRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->group(function () {
            Route::post('/_t/events/{community}', fn (Community $community, StoreEventRequest $r) => response()->json($r->toData()))->whereNumber('community');
            Route::post('/_t/events/{event}/update', fn (CommunityEvent $event, UpdateEventRequest $r) => response()->json($r->toData()))->whereNumber('event');
            Route::post('/_t/events/{event}/comment', fn (StoreEventCommentRequest $r) => response()->json(['ok' => true]))->whereNumber('event');
        });
    }

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
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Meetup',
            'body' => 'Come along.',
            'open_date' => now()->addWeek()->format('Y-m-d'),
            'open_date_comment' => '19:00-',
            'area' => 'Shibuya',
            'application_deadline' => now()->addDays(3)->format('Y-m-d'),
            'capacity' => 10,
        ], $overrides);
    }

    public function test_a_valid_payload_creates(): void
    {
        $community = Community::factory()->create();
        $member = $this->joined($community);

        $this->actingAs($member)
            ->post("/_t/events/{$community->getKey()}", $this->validPayload())
            ->assertOk();
    }

    public function test_open_date_must_be_date_only(): void
    {
        $community = Community::factory()->create();
        $member = $this->joined($community);

        $this->actingAs($member)
            ->post("/_t/events/{$community->getKey()}", $this->validPayload(['open_date' => now()->addWeek()->format('Y-m-d').' 12:34:56']))
            ->assertSessionHasErrors('open_date');
    }

    public function test_create_rejects_a_past_open_date(): void
    {
        $community = Community::factory()->create();
        $member = $this->joined($community);

        $this->actingAs($member)
            ->post("/_t/events/{$community->getKey()}", $this->validPayload(['open_date' => now()->subDay()->format('Y-m-d'), 'application_deadline' => null]))
            ->assertSessionHasErrors('open_date');
    }

    public function test_deadline_must_be_on_or_before_the_open_date(): void
    {
        $community = Community::factory()->create();
        $member = $this->joined($community);

        $this->actingAs($member)
            ->post("/_t/events/{$community->getKey()}", $this->validPayload([
                'open_date' => now()->addWeek()->format('Y-m-d'),
                'application_deadline' => now()->addWeek()->addDay()->format('Y-m-d'),
            ]))
            ->assertSessionHasErrors('application_deadline');
    }

    public function test_deadline_must_not_be_in_the_past(): void
    {
        $community = Community::factory()->create();
        $member = $this->joined($community);

        $this->actingAs($member)
            ->post("/_t/events/{$community->getKey()}", $this->validPayload(['application_deadline' => now()->subDay()->format('Y-m-d')]))
            ->assertSessionHasErrors('application_deadline');
    }

    public function test_capacity_may_not_be_negative(): void
    {
        $community = Community::factory()->create();
        $member = $this->joined($community);

        $this->actingAs($member)
            ->post("/_t/events/{$community->getKey()}", $this->validPayload(['capacity' => -1]))
            ->assertSessionHasErrors('capacity');
    }

    public function test_open_date_comment_must_be_a_string(): void
    {
        $community = Community::factory()->create();
        $member = $this->joined($community);

        $this->actingAs($member)
            ->post("/_t/events/{$community->getKey()}", $this->validPayload(['open_date_comment' => ['injected']]))
            ->assertSessionHasErrors('open_date_comment');
    }

    public function test_open_date_comment_may_be_omitted(): void
    {
        $community = Community::factory()->create();
        $member = $this->joined($community);
        $payload = $this->validPayload();
        unset($payload['open_date_comment']);

        $this->actingAs($member)
            ->post("/_t/events/{$community->getKey()}", $payload)
            ->assertOk();
    }

    public function test_creating_is_404_for_a_non_member(): void
    {
        $community = Community::factory()->create();
        $stranger = Member::factory()->create();

        $this->actingAs($stranger)
            ->post("/_t/events/{$community->getKey()}", $this->validPayload())
            ->assertNotFound();
    }

    public function test_editing_allows_a_past_open_date(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);

        $this->actingAs($author)
            ->post("/_t/events/{$event->getKey()}/update", $this->validPayload(['open_date' => now()->subWeek()->format('Y-m-d'), 'application_deadline' => null]))
            ->assertOk();
    }

    public function test_commenting_requires_a_body(): void
    {
        $community = Community::factory()->create();
        $member = $this->joined($community);
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey()]);

        $this->actingAs($member)
            ->post("/_t/events/{$event->getKey()}/comment", [])
            ->assertSessionHasErrors('body');

        $this->actingAs($member)
            ->post("/_t/events/{$event->getKey()}/comment", ['body' => 'Joining!'])
            ->assertOk();
    }

    public function test_commenting_is_404_for_a_non_member(): void
    {
        $community = Community::factory()->create();
        $stranger = Member::factory()->create();
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey()]);

        $this->actingAs($stranger)
            ->post("/_t/events/{$event->getKey()}/comment", ['body' => 'Sneaking in'])
            ->assertNotFound();
    }
}
