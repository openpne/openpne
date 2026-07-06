<?php

namespace Tests\Feature\Community;

use App\Features\Community\CommunityRole;
use App\Http\Requests\CommunityEvent\StoreEventRequest;
use App\Http\Requests\CommunityEvent\UpdateEventRequest;
use App\Http\Requests\CommunityTopic\StoreTopicRequest;
use App\Http\Requests\CommunityTopic\UpdateTopicRequest;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * CommunityTopic and CommunityEvent are an intentional parallel hierarchy, but their form requests
 * share a text-post contract (a required, whitespace-stripped name and body) that must not drift on
 * one side only. This pins that shared shape structurally (identical rules) and behaviorally (both
 * reject a whitespace-only name/body), so changing one request without the other fails here.
 */
class CommunityPostRequestParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->group(function () {
            Route::post('/_p/topics/{community}', fn (Community $community, StoreTopicRequest $r) => response()->json(['ok' => true]))->whereNumber('community');
            Route::post('/_p/events/{community}', fn (Community $community, StoreEventRequest $r) => response()->json(['ok' => true]))->whereNumber('community');
        });
    }

    public function test_create_requests_share_the_name_and_body_rules(): void
    {
        $topic = Arr::only((new StoreTopicRequest)->rules(), ['name', 'body']);
        $event = Arr::only((new StoreEventRequest)->rules(), ['name', 'body']);

        $this->assertSame($topic, $event, 'Topic and event create requests must keep identical name/body rules; a one-sided change is accidental drift.');
    }

    public function test_update_requests_share_the_remove_images_rules(): void
    {
        $topic = Arr::only((new UpdateTopicRequest)->rules(), ['remove_images', 'remove_images.*']);
        $event = Arr::only((new UpdateEventRequest)->rules(), ['remove_images', 'remove_images.*']);

        $this->assertSame($topic, $event, 'Topic and event edit requests must manage image removal identically.');
    }

    public function test_both_reject_a_whitespace_only_name(): void
    {
        $community = Community::factory()->create();
        $member = $this->joined($community);
        $id = $community->getKey();

        $this->actingAs($member)->post("/_p/topics/{$id}", $this->topicPayload(['name' => '   ']))->assertSessionHasErrors('name');
        $this->actingAs($member)->post("/_p/events/{$id}", $this->eventPayload(['name' => '   ']))->assertSessionHasErrors('name');
    }

    public function test_both_reject_a_whitespace_only_body(): void
    {
        $community = Community::factory()->create();
        $member = $this->joined($community);
        $id = $community->getKey();

        $this->actingAs($member)->post("/_p/topics/{$id}", $this->topicPayload(['body' => '   ']))->assertSessionHasErrors('body');
        $this->actingAs($member)->post("/_p/events/{$id}", $this->eventPayload(['body' => '   ']))->assertSessionHasErrors('body');
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
