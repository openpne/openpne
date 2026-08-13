<?php

namespace Tests\Feature\GroupTopic\Requests;

use App\Features\Group\GroupRole;
use App\Http\Requests\GroupTopic\StoreTopicCommentRequest;
use App\Http\Requests\GroupTopic\StoreTopicRequest;
use App\Http\Requests\GroupTopic\UpdateTopicRequest;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupTopic;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Group\GroupPostRequestParityTest;
use Tests\TestCase;

/**
 * Drives the topic form requests through throwaway routes (the real routes land with the Classic
 * adapter), to pin the OpenPNE 3 validation rules and the 404-on-refusal authorization. The
 * name/body rules shared with events are additionally guarded against one-sided drift by
 * {@see GroupPostRequestParityTest}.
 */
class GroupTopicRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->group(function () {
            Route::post('/_t/topics/{group}', fn (Group $group, StoreTopicRequest $r) => response()->json($r->toData()))->whereNumber('group');
            Route::post('/_t/topics/{topic}/update', fn (GroupTopic $topic, UpdateTopicRequest $r) => response()->json($r->toData()))->whereNumber('topic');
            Route::post('/_t/topics/{topic}/comment', fn (StoreTopicCommentRequest $r) => response()->json(['ok' => true]))->whereNumber('topic');
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

    public function test_a_valid_payload_creates(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);

        $this->actingAs($member)
            ->post("/_t/topics/{$group->getKey()}", ['name' => 'Welcome', 'body' => 'Say hello.'])
            ->assertOk();
    }

    public function test_create_accepts_a_markdown_format(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);

        $this->actingAs($member)
            ->post("/_t/topics/{$group->getKey()}", ['name' => 'MD', 'body' => '**b**', 'format' => 'markdown'])
            ->assertOk()
            ->assertJsonPath('format', 'markdown');
    }

    public function test_create_rejects_the_op3_format(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);

        $this->actingAs($member)
            ->post("/_t/topics/{$group->getKey()}", ['name' => 'MD', 'body' => 'x', 'format' => 'op3'])
            ->assertSessionHasErrors('format');
    }

    public function test_create_requires_a_name(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);

        $this->actingAs($member)
            ->post("/_t/topics/{$group->getKey()}", ['body' => 'No title.'])
            ->assertSessionHasErrors('name');
    }

    public function test_create_requires_a_body(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);

        $this->actingAs($member)
            ->post("/_t/topics/{$group->getKey()}", ['name' => 'No body'])
            ->assertSessionHasErrors('body');
    }

    public function test_creating_is_404_for_a_non_member(): void
    {
        $group = Group::factory()->create();
        $stranger = Member::factory()->create();

        $this->actingAs($stranger)
            ->post("/_t/topics/{$group->getKey()}", ['name' => 'Welcome', 'body' => 'Say hello.'])
            ->assertNotFound();
    }

    public function test_the_author_may_edit(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);

        $this->actingAs($author)
            ->post("/_t/topics/{$topic->getKey()}/update", ['name' => 'Edited', 'body' => 'Updated body.'])
            ->assertOk();
    }

    public function test_editing_is_404_for_a_non_editor(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $stranger = $this->joined($group);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);

        $this->actingAs($stranger)
            ->post("/_t/topics/{$topic->getKey()}/update", ['name' => 'Hijack', 'body' => 'Not mine.'])
            ->assertNotFound();
    }

    public function test_commenting_requires_a_body(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey()]);

        $this->actingAs($member)
            ->post("/_t/topics/{$topic->getKey()}/comment", [])
            ->assertSessionHasErrors('body');

        $this->actingAs($member)
            ->post("/_t/topics/{$topic->getKey()}/comment", ['body' => 'Nice topic!'])
            ->assertOk();
    }

    public function test_commenting_is_404_for_a_non_member(): void
    {
        $group = Group::factory()->create();
        $stranger = Member::factory()->create();
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey()]);

        $this->actingAs($stranger)
            ->post("/_t/topics/{$topic->getKey()}/comment", ['body' => 'Sneaking in'])
            ->assertNotFound();
    }
}
