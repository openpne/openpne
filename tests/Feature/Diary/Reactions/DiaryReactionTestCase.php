<?php

namespace Tests\Feature\Diary\Reactions;

use App\Features\Reactions\ReactionVocabulary;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

abstract class DiaryReactionTestCase extends TestCase
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

    protected function diary(?Member $author = null): Diary
    {
        return Diary::factory()->create(['member_id' => ($author ?? Member::factory()->create())->getKey()]);
    }

    protected function comment(Diary $diary, ?Member $author = null): DiaryComment
    {
        return DiaryComment::factory()->create([
            'diary_id' => $diary->getKey(),
            'member_id' => ($author ?? Member::factory()->create())->getKey(),
        ]);
    }

    protected function path(Diary|DiaryComment $target): string
    {
        return $target instanceof Diary ? "/diary/{$target->getKey()}/reactions" : "/diary/comment/{$target->getKey()}/reactions";
    }

    protected function react(Member $member, Diary|DiaryComment $target, ?string $emoji = null): TestResponse
    {
        return $this->actingAs($member)->postJson($this->path($target), ['emoji' => $emoji ?? $this->emoji(0)]);
    }

    protected function unreact(Member $member, Diary|DiaryComment $target, ?string $emoji = null): TestResponse
    {
        return $this->actingAs($member)->postJson($this->path($target).'/delete', ['emoji' => $emoji ?? $this->emoji(0)]);
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
