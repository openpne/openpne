<?php

namespace Tests\Feature\GroupTalk;

use App\Features\Reactions\ReactionVocabulary;
use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\Member;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/** Shared setup for the reaction suites: a message to react to, and the two writes over HTTP. */
abstract class TalkReactionTestCase extends TalkTestCase
{
    /** An emoji the site offers, by position — the vocabulary's size is never written down here. */
    protected function emoji(int $index): string
    {
        return ReactionVocabulary::all()[$index];
    }

    protected function message(Group $group, ?Member $author = null): GroupMessage
    {
        return GroupMessage::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => ($author ?? $this->memberOf($group))->getKey(),
        ]);
    }

    protected function react(Member $member, Group $group, GroupMessage $message, ?string $emoji = null): TestResponse
    {
        return $this->actingAs($member)->postJson(
            "/groups/{$group->getKey()}/talk/messages/{$message->getKey()}/reactions",
            ['emoji' => $emoji ?? $this->emoji(0)],
        );
    }

    protected function unreact(Member $member, Group $group, GroupMessage $message, ?string $emoji = null): TestResponse
    {
        return $this->actingAs($member)->postJson(
            "/groups/{$group->getKey()}/talk/messages/{$message->getKey()}/reactions/delete",
            ['emoji' => $emoji ?? $this->emoji(0)],
        );
    }

    /** The group's reaction high-water mark. */
    protected function seq(Group $group): int
    {
        return (int) DB::table('groups')->where('id', $group->getKey())->value('talk_reaction_seq');
    }
}
