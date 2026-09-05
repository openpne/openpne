<?php

namespace Tests\Feature\Group;

use App\Features\Group\GroupRole;
use App\Http\Requests\GroupEvent\StoreEventRequest;
use App\Http\Requests\GroupEvent\UpdateEventRequest;
use App\Http\Requests\GroupTopic\StoreTopicRequest;
use App\Http\Requests\GroupTopic\UpdateTopicRequest;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The two form requests share a text-post contract that must not drift on one side only, pinned
 * here both structurally (identical rules) and behaviorally.
 */
class GroupPostRequestParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->group(function () {
            Route::post('/_p/topics/{group}', fn (Group $group, StoreTopicRequest $r) => response()->json(['ok' => true]))->whereNumber('group');
            Route::post('/_p/events/{group}', fn (Group $group, StoreEventRequest $r) => response()->json(['ok' => true]))->whereNumber('group');
        });
    }

    public function test_create_requests_share_the_name_and_body_rules(): void
    {
        $topic = Arr::only((new StoreTopicRequest)->rules(), ['name', 'body']);
        $event = Arr::only((new StoreEventRequest)->rules(), ['name', 'body']);

        // assertEquals, not assertSame: rule objects (e.g. MaxBytes) are distinct instances per
        // request, and equality still pins the shared shape — a one-sided rule or a differing cap fails.
        $this->assertEquals($topic, $event, 'Topic and event create requests must keep identical name/body rules; a one-sided change is accidental drift.');
    }

    public function test_update_requests_share_the_remove_images_rules(): void
    {
        $topic = Arr::only((new UpdateTopicRequest)->rules(), ['remove_images', 'remove_images.*']);
        $event = Arr::only((new UpdateEventRequest)->rules(), ['remove_images', 'remove_images.*']);

        $this->assertSame($topic, $event, 'Topic and event edit requests must manage image removal identically.');
    }

    public function test_both_reject_a_whitespace_only_name(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);
        $id = $group->getKey();

        $this->actingAs($member)->post("/_p/topics/{$id}", $this->topicPayload(['name' => '   ']))->assertSessionHasErrors('name');
        $this->actingAs($member)->post("/_p/events/{$id}", $this->eventPayload(['name' => '   ']))->assertSessionHasErrors('name');
    }

    public function test_both_reject_a_whitespace_only_body(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);
        $id = $group->getKey();

        $this->actingAs($member)->post("/_p/topics/{$id}", $this->topicPayload(['body' => '   ']))->assertSessionHasErrors('body');
        $this->actingAs($member)->post("/_p/events/{$id}", $this->eventPayload(['body' => '   ']))->assertSessionHasErrors('body');
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
    private function topicPayload(array $overrides = []): array
    {
        return array_merge(['name' => 'Welcome', 'body' => 'Say hello.'], $overrides);
    }

    /** @return array<string, mixed> */
    private function eventPayload(array $overrides = []): array
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
}
