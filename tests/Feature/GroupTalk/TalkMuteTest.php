<?php

namespace Tests\Feature\GroupTalk;

use App\Features\GroupTopic\TopicReadAccess;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

/** Per-group quiet. Like the cursor, it lives on the membership row, so only a member holds one. */
class TalkMuteTest extends TalkTestCase
{
    private function isMuted(int $groupId, int $memberId): bool
    {
        return (bool) DB::table('group_members')
            ->where('group_id', $groupId)->where('member_id', $memberId)->value('is_talk_muted');
    }

    public function test_a_member_mutes_and_unmutes(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        $url = "/groups/{$group->getKey()}/talk/mute";

        $this->actingAs($member)->postJson($url, ['muted' => true])->assertNoContent();
        $this->assertTrue($this->isMuted($group->getKey(), $member->getKey()));

        $this->actingAs($member)->postJson($url, ['muted' => false])->assertNoContent();
        $this->assertFalse($this->isMuted($group->getKey(), $member->getKey()));
    }

    /** Explicit state, not a flip: two taps that race land on the same answer. */
    public function test_muting_twice_is_idempotent(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        $url = "/groups/{$group->getKey()}/talk/mute";

        $this->actingAs($member)->postJson($url, ['muted' => true])->assertNoContent();
        $this->actingAs($member)->postJson($url, ['muted' => true])->assertNoContent();

        $this->assertTrue($this->isMuted($group->getKey(), $member->getKey()));
    }

    public function test_a_non_member_reader_cannot_mute(): void
    {
        $group = $this->group(TopicReadAccess::Everyone);
        $outsider = Member::factory()->create();

        $this->actingAs($outsider)->get("/groups/{$group->getKey()}/talk")->assertOk();
        $this->actingAs($outsider)
            ->postJson("/groups/{$group->getKey()}/talk/mute", ['muted' => true])
            ->assertNotFound();
    }

    public function test_a_missing_state_is_a_validation_error(): void
    {
        $group = $this->group();

        $this->actingAs($this->memberOf($group))
            ->postJson("/groups/{$group->getKey()}/talk/mute", [])
            ->assertJsonValidationErrorFor('muted');
    }

    public function test_the_talk_page_carries_the_membership_and_mute_state(): void
    {
        $group = $this->group(TopicReadAccess::Everyone);
        $member = $this->memberOf($group);

        $this->actingAs($member)->get("/groups/{$group->getKey()}/talk")
            ->assertInertia(fn ($page) => $page->where('isMember', true)->where('isMuted', false));

        $this->actingAs($member)->postJson("/groups/{$group->getKey()}/talk/mute", ['muted' => true]);

        $this->actingAs($member)->get("/groups/{$group->getKey()}/talk")
            ->assertInertia(fn ($page) => $page->where('isMuted', true));

        // A reader who is not a member is offered neither control.
        $this->actingAs(Member::factory()->create())->get("/groups/{$group->getKey()}/talk")
            ->assertInertia(fn ($page) => $page->where('isMember', false)->where('isMuted', false));
    }

    /** Leaving takes the mute with the membership, so rejoining starts audible. */
    public function test_leaving_clears_the_mute(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        $this->actingAs($member)->postJson("/groups/{$group->getKey()}/talk/mute", ['muted' => true]);

        $this->actingAs($member)->post("/groups/{$group->getKey()}/quit")->assertRedirect();

        $this->assertDatabaseMissing('group_members', [
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
        ]);
    }
}
