<?php

namespace Tests\Feature\CommunityTopic\Requests;

use App\Features\Community\CommunityRole;
use App\Http\Requests\CommunityTopic\StoreTopicCommentRequest;
use App\Http\Requests\CommunityTopic\StoreTopicRequest;
use App\Http\Requests\CommunityTopic\UpdateTopicRequest;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\CommunityTopic;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Community\CommunityPostRequestParityTest;
use Tests\TestCase;

/**
 * Drives the topic form requests through throwaway routes (the real routes land with the Classic
 * adapter), to pin the OpenPNE 3 validation rules and the 404-on-refusal authorization. The
 * name/body rules shared with events are additionally guarded against one-sided drift by
 * {@see CommunityPostRequestParityTest}.
 */
class CommunityTopicRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->group(function () {
            Route::post('/_t/topics/{community}', fn (Community $community, StoreTopicRequest $r) => response()->json($r->toData()))->whereNumber('community');
            Route::post('/_t/topics/{topic}/update', fn (CommunityTopic $topic, UpdateTopicRequest $r) => response()->json($r->toData()))->whereNumber('topic');
            Route::post('/_t/topics/{topic}/comment', fn (StoreTopicCommentRequest $r) => response()->json(['ok' => true]))->whereNumber('topic');
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

    public function test_a_valid_payload_creates(): void
    {
        $community = Community::factory()->create();
        $member = $this->joined($community);

        $this->actingAs($member)
            ->post("/_t/topics/{$community->getKey()}", ['name' => 'Welcome', 'body' => 'Say hello.'])
            ->assertOk();
    }

    public function test_create_requires_a_name(): void
    {
        $community = Community::factory()->create();
        $member = $this->joined($community);

        $this->actingAs($member)
            ->post("/_t/topics/{$community->getKey()}", ['body' => 'No title.'])
            ->assertSessionHasErrors('name');
    }

    public function test_create_requires_a_body(): void
    {
        $community = Community::factory()->create();
        $member = $this->joined($community);

        $this->actingAs($member)
            ->post("/_t/topics/{$community->getKey()}", ['name' => 'No body'])
            ->assertSessionHasErrors('body');
    }

    public function test_creating_is_404_for_a_non_member(): void
    {
        $community = Community::factory()->create();
        $stranger = Member::factory()->create();

        $this->actingAs($stranger)
            ->post("/_t/topics/{$community->getKey()}", ['name' => 'Welcome', 'body' => 'Say hello.'])
            ->assertNotFound();
    }

    public function test_the_author_may_edit(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);

        $this->actingAs($author)
            ->post("/_t/topics/{$topic->getKey()}/update", ['name' => 'Edited', 'body' => 'Updated body.'])
            ->assertOk();
    }

    public function test_editing_is_404_for_a_non_editor(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $stranger = $this->joined($community);
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);

        $this->actingAs($stranger)
            ->post("/_t/topics/{$topic->getKey()}/update", ['name' => 'Hijack', 'body' => 'Not mine.'])
            ->assertNotFound();
    }

    public function test_commenting_requires_a_body(): void
    {
        $community = Community::factory()->create();
        $member = $this->joined($community);
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey()]);

        $this->actingAs($member)
            ->post("/_t/topics/{$topic->getKey()}/comment", [])
            ->assertSessionHasErrors('body');

        $this->actingAs($member)
            ->post("/_t/topics/{$topic->getKey()}/comment", ['body' => 'Nice topic!'])
            ->assertOk();
    }

    public function test_commenting_is_404_for_a_non_member(): void
    {
        $community = Community::factory()->create();
        $stranger = Member::factory()->create();
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey()]);

        $this->actingAs($stranger)
            ->post("/_t/topics/{$topic->getKey()}/comment", ['body' => 'Sneaking in'])
            ->assertNotFound();
    }
}
