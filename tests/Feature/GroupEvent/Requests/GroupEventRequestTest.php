<?php

namespace Tests\Feature\GroupEvent\Requests;

use App\Features\Group\GroupRole;
use App\Http\Requests\GroupEvent\StoreEventCommentRequest;
use App\Http\Requests\GroupEvent\StoreEventRequest;
use App\Http\Requests\GroupEvent\UpdateEventRequest;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Drives the event form requests through throwaway routes (the real routes land with the Classic
 * adapter), to pin the OpenPNE 3 validation rules and the 404-on-refusal authorization.
 */
class GroupEventRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->group(function () {
            Route::post('/_t/events/{group}', fn (Group $group, StoreEventRequest $r) => response()->json($r->toData()))->whereNumber('group');
            Route::post('/_t/events/{event}/update', fn (GroupEvent $event, UpdateEventRequest $r) => response()->json($r->toData()))->whereNumber('event');
            Route::post('/_t/events/{event}/comment', fn (StoreEventCommentRequest $r) => response()->json(['ok' => true]))->whereNumber('event');
        });
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
        $group = Group::factory()->create();
        $member = $this->joined($group);

        $this->actingAs($member)
            ->post("/_t/events/{$group->getKey()}", $this->validPayload())
            ->assertOk();
    }

    public function test_create_accepts_a_markdown_format(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);

        $this->actingAs($member)
            ->post("/_t/events/{$group->getKey()}", $this->validPayload(['format' => 'markdown']))
            ->assertOk()
            ->assertJsonPath('format', 'markdown');
    }

    public function test_create_rejects_the_op3_format(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);

        $this->actingAs($member)
            ->post("/_t/events/{$group->getKey()}", $this->validPayload(['format' => 'op3']))
            ->assertSessionHasErrors('format');
    }

    public function test_open_date_must_be_date_only(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);

        $this->actingAs($member)
            ->post("/_t/events/{$group->getKey()}", $this->validPayload(['open_date' => now()->addWeek()->format('Y-m-d').' 12:34:56']))
            ->assertSessionHasErrors('open_date');
    }

    public function test_create_rejects_a_past_open_date(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);

        $this->actingAs($member)
            ->post("/_t/events/{$group->getKey()}", $this->validPayload(['open_date' => now()->subDay()->format('Y-m-d'), 'application_deadline' => null]))
            ->assertSessionHasErrors('open_date');
    }

    public function test_deadline_must_be_on_or_before_the_open_date(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);

        $this->actingAs($member)
            ->post("/_t/events/{$group->getKey()}", $this->validPayload([
                'open_date' => now()->addWeek()->format('Y-m-d'),
                'application_deadline' => now()->addWeek()->addDay()->format('Y-m-d'),
            ]))
            ->assertSessionHasErrors('application_deadline');
    }

    public function test_deadline_must_not_be_in_the_past(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);

        $this->actingAs($member)
            ->post("/_t/events/{$group->getKey()}", $this->validPayload(['application_deadline' => now()->subDay()->format('Y-m-d')]))
            ->assertSessionHasErrors('application_deadline');
    }

    public function test_capacity_may_not_be_negative(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);

        $this->actingAs($member)
            ->post("/_t/events/{$group->getKey()}", $this->validPayload(['capacity' => -1]))
            ->assertSessionHasErrors('capacity');
    }

    public function test_open_date_comment_must_be_a_string(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);

        $this->actingAs($member)
            ->post("/_t/events/{$group->getKey()}", $this->validPayload(['open_date_comment' => ['injected']]))
            ->assertSessionHasErrors('open_date_comment');
    }

    public function test_open_date_comment_may_be_omitted(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);
        $payload = $this->validPayload();
        unset($payload['open_date_comment']);

        $this->actingAs($member)
            ->post("/_t/events/{$group->getKey()}", $payload)
            ->assertOk();
    }

    public function test_creating_is_404_for_a_non_member(): void
    {
        $group = Group::factory()->create();
        $stranger = Member::factory()->create();

        $this->actingAs($stranger)
            ->post("/_t/events/{$group->getKey()}", $this->validPayload())
            ->assertNotFound();
    }

    public function test_editing_allows_a_past_open_date(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);

        $this->actingAs($author)
            ->post("/_t/events/{$event->getKey()}/update", $this->validPayload(['open_date' => now()->subWeek()->format('Y-m-d'), 'application_deadline' => null]))
            ->assertOk();
    }

    public function test_commenting_requires_a_body(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey()]);

        $this->actingAs($member)
            ->post("/_t/events/{$event->getKey()}/comment", [])
            ->assertSessionHasErrors('body');

        $this->actingAs($member)
            ->post("/_t/events/{$event->getKey()}/comment", ['body' => 'Joining!'])
            ->assertOk();
    }

    public function test_commenting_is_404_for_a_non_member(): void
    {
        $group = Group::factory()->create();
        $stranger = Member::factory()->create();
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey()]);

        $this->actingAs($stranger)
            ->post("/_t/events/{$event->getKey()}/comment", ['body' => 'Sneaking in'])
            ->assertNotFound();
    }
}
