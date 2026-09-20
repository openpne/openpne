<?php

namespace Tests\Feature\Group\Reactions;

use App\Features\Group\GroupRole;
use App\Features\GroupTopic\TopicReadAccess;
use App\Features\Reactions\ReactionVocabulary;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Models\GroupMember;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

abstract class BoardReactionTestCase extends TestCase
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

    protected function group(bool $membersOnly = false): Group
    {
        return Group::factory()->create($membersOnly ? ['topic_read_access' => TopicReadAccess::MembersOnly] : []);
    }

    protected function joined(Group $group, GroupRole $role = GroupRole::Member): Member
    {
        $member = Member::factory()->create();
        GroupMember::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
            'role' => $role,
        ]);

        return $member;
    }

    protected function topicComment(Group $group, ?Member $author = null): GroupTopicComment
    {
        $author ??= $this->joined($group);

        return GroupTopicComment::factory()->create([
            'group_topic_id' => GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()])->getKey(),
            'member_id' => $author->getKey(),
        ]);
    }

    protected function eventComment(Group $group, ?Member $author = null): GroupEventComment
    {
        $author ??= $this->joined($group);

        return GroupEventComment::factory()->create([
            'group_event_id' => GroupEvent::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()])->getKey(),
            'member_id' => $author->getKey(),
        ]);
    }

    protected function topicBody(Group $group, ?Member $author = null): GroupTopic
    {
        $author ??= $this->joined($group);

        return GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);
    }

    protected function eventBody(Group $group, ?Member $author = null): GroupEvent
    {
        $author ??= $this->joined($group);

        return GroupEvent::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);
    }

    protected function path(GroupTopic|GroupEvent|GroupTopicComment|GroupEventComment $target): string
    {
        $board = $target instanceof GroupTopic || $target instanceof GroupTopicComment ? 'topics' : 'events';
        $comments = $target instanceof GroupTopicComment || $target instanceof GroupEventComment ? '/comments' : '';

        return "/{$board}{$comments}/{$target->getKey()}/reactions";
    }

    protected function react(Member $member, GroupTopic|GroupEvent|GroupTopicComment|GroupEventComment $target, ?string $emoji = null): TestResponse
    {
        return $this->actingAs($member)->postJson($this->path($target), ['emoji' => $emoji ?? $this->emoji(0)]);
    }

    protected function unreact(Member $member, GroupTopic|GroupEvent|GroupTopicComment|GroupEventComment $target, ?string $emoji = null): TestResponse
    {
        return $this->actingAs($member)->postJson($this->path($target).'/delete', ['emoji' => $emoji ?? $this->emoji(0)]);
    }
}
