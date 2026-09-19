<?php

namespace Tests\Feature\Timeline\Reactions;

use App\Features\Reactions\ReactionVocabulary;
use App\Models\Member;
use App\Models\TimelinePost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

abstract class TimelineReactionTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['openpne.surface_mode' => 'modern_default']);
    }

    /** An emoji the site offers, by position — the vocabulary's size is never written down here. */
    protected function emoji(int $index): string
    {
        return ReactionVocabulary::all()[$index];
    }

    protected function rootPost(?Member $author = null): TimelinePost
    {
        return TimelinePost::factory()->create(['member_id' => ($author ?? Member::factory()->create())->getKey()]);
    }

    protected function reply(TimelinePost $root, ?Member $author = null): TimelinePost
    {
        return TimelinePost::factory()->replyTo($root)->create(['member_id' => ($author ?? Member::factory()->create())->getKey()]);
    }

    protected function react(Member $member, TimelinePost $post, ?string $emoji = null): TestResponse
    {
        return $this->actingAs($member)->postJson("/timeline/{$post->getKey()}/reactions", ['emoji' => $emoji ?? $this->emoji(0)]);
    }

    protected function unreact(Member $member, TimelinePost $post, ?string $emoji = null): TestResponse
    {
        return $this->actingAs($member)->postJson("/timeline/{$post->getKey()}/reactions/delete", ['emoji' => $emoji ?? $this->emoji(0)]);
    }

    protected function befriend(Member $a, Member $b): void
    {
        DB::table('friendships')->insert([
            ['member_id' => $a->getKey(), 'friend_id' => $b->getKey()],
            ['member_id' => $b->getKey(), 'friend_id' => $a->getKey()],
        ]);
    }

    protected function block(Member $blocker, Member $blocked): void
    {
        DB::table('member_blocks')->insert(['blocker_id' => $blocker->getKey(), 'blocked_id' => $blocked->getKey()]);
    }
}
