<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Features\Block\BlockLookup;
use App\Features\Group\GroupMembership;
use App\Features\Group\GroupRole;
use App\Features\GroupTopic\TopicReadAccess;
use App\LinkCard\InternalCardTarget;
use App\Models\Diary;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupMessage;
use App\Models\GroupTopic;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\ViewerRelations;
use App\Support\Visibility;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Every branch is set up explicitly: left to the factories, a run would exercise one arm of each
 * rule (a stranger, no block, no membership) and pass while the others were wrong.
 */
class ViewerRelationsTest extends TestCase
{
    use RefreshDatabase;

    private Member $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->viewer = Member::factory()->create();
    }

    public function test_a_block_reads_the_same_whether_or_not_it_was_read_in_bulk(): void
    {
        $blocker = Member::factory()->create();
        $stranger = Member::factory()->create();
        $this->block($blocker, $this->viewer);

        foreach ([[$blocker, true], [$stranger, false], [$this->viewer, false]] as [$owner, $expected]) {
            $cold = $this->cold(fn (): bool => BlockLookup::ownerBlocksViewer($owner, $this->viewer));
            $warm = $this->warm(
                fn (ViewerRelations $relations) => $relations->warmBlocks($this->viewer, [$owner->getKey()]),
                fn (): bool => BlockLookup::ownerBlocksViewer($owner, $this->viewer),
            );

            $this->assertSame($expected, $cold, "The single-row read is wrong for member {$owner->id}.");
            $this->assertSame($cold, $warm, "The bulk read disagrees for member {$owner->id}.");
        }
    }

    public function test_a_friendship_reads_the_same_whether_or_not_it_was_read_in_bulk(): void
    {
        $friend = Member::factory()->create();
        $stranger = Member::factory()->create();
        $this->befriend($this->viewer, $friend);

        foreach ([[$friend, true], [$stranger, false]] as [$other, $expected]) {
            $cold = $this->cold(fn (): bool => $this->viewer->isFriendsWith($other));
            $warm = $this->warm(
                fn (ViewerRelations $relations) => $relations->warmFriends($this->viewer, [$other->getKey()]),
                fn (): bool => $this->viewer->isFriendsWith($other),
            );

            $this->assertSame($expected, $cold, "The single-row read is wrong for member {$other->id}.");
            $this->assertSame($cold, $warm, "The bulk read disagrees for member {$other->id}.");
        }
    }

    public function test_a_role_reads_the_same_whether_or_not_it_was_read_in_bulk(): void
    {
        $joined = Group::factory()->create();
        $administered = Group::factory()->create();
        $other = Group::factory()->create();
        GroupMember::factory()->create(['group_id' => $joined->id, 'member_id' => $this->viewer->id, 'role' => GroupRole::Member]);
        GroupMember::factory()->admin()->create(['group_id' => $administered->id, 'member_id' => $this->viewer->id]);

        foreach ([[$joined, GroupRole::Member], [$administered, GroupRole::Admin], [$other, null]] as [$group, $expected]) {
            $cold = $this->cold(fn (): ?GroupRole => GroupMembership::roleOf($group, $this->viewer));
            $warm = $this->warm(
                fn (ViewerRelations $relations) => $relations->warmRoles($this->viewer, [$group->getKey()]),
                fn (): ?GroupRole => GroupMembership::roleOf($group, $this->viewer),
            );

            $this->assertSame($expected, $cold, "The single-row read is wrong for group {$group->id}.");
            $this->assertSame($cold, $warm, "The bulk read disagrees for group {$group->id}.");
        }
    }

    public function test_every_card_rule_reads_the_same_whether_or_not_it_was_read_in_bulk(): void
    {
        foreach ($this->cases() as $name => [$target, $record, $expected]) {
            $cold = $this->cold(fn (): bool => $target->canView($record, $this->viewer));
            $warm = $this->warm(
                fn () => $target->warmRelations(new Collection([$record]), $this->viewer),
                fn (): bool => $target->canView($record, $this->viewer),
            );

            $this->assertSame($expected, $cold, "The rule itself is wrong for: {$name}.");
            $this->assertSame($cold, $warm, "Reading the page's relations in bulk changed the answer for: {$name}.");
        }
    }

    public function test_a_warmed_page_answers_without_asking_again(): void
    {
        // Teeth for everything above: agreement is trivially true if the bulk read never answers.
        $stranger = Member::factory()->create();
        $diary = $this->diaryOf($stranger, Visibility::Members);
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        GroupMember::factory()->create(['group_id' => $group->id, 'member_id' => $this->viewer->id]);
        // Read as a page reads them, relations and all: a lazy load here would be counted as the
        // rule asking, which is not what is being measured.
        $topic = $this->topicIn($group, $stranger);

        $this->cold(fn () => null);
        InternalCardTarget::Diary->warmRelations(new Collection([$diary]), $this->viewer);
        InternalCardTarget::Topic->warmRelations(new Collection([$topic]), $this->viewer);

        $asked = $this->queriesDuring(function () use ($diary, $topic): void {
            InternalCardTarget::Diary->canView($diary, $this->viewer);
            InternalCardTarget::Topic->canView($topic, $this->viewer);
        });

        $this->assertSame([], $asked, 'A warmed page went back to the database: '.implode(' | ', $asked));
    }

    public function test_a_pair_the_page_never_named_is_read_as_it_always_was(): void
    {
        $blocker = Member::factory()->create();
        $named = Member::factory()->create();
        $this->block($blocker, $this->viewer);

        $this->cold(fn () => null);
        app(ViewerRelations::class)->warmBlocks($this->viewer, [$named->getKey()]);

        $this->assertTrue(BlockLookup::ownerBlocksViewer($blocker, $this->viewer), 'A pair outside the batch was answered from it.');
    }

    public function test_one_readers_relations_never_answer_for_another(): void
    {
        $owner = Member::factory()->create();
        $other = Member::factory()->create();
        $this->block($owner, $other);

        $this->cold(fn () => null);
        // The viewer is not blocked but the other reader is, and both pairs name the same owner.
        app(ViewerRelations::class)->warmBlocks($this->viewer, [$owner->getKey()]);

        $this->assertFalse(BlockLookup::ownerBlocksViewer($owner, $this->viewer));
        $this->assertTrue(BlockLookup::ownerBlocksViewer($owner, $other), "One reader's answer was given to another.");
    }

    public function test_a_write_drops_what_was_memoised_before_it(): void
    {
        $owner = Member::factory()->create();

        $this->cold(fn () => null);
        app(ViewerRelations::class)->warmBlocks($this->viewer, [$owner->getKey()]);
        $this->assertFalse(BlockLookup::ownerBlocksViewer($owner, $this->viewer));

        $this->block($owner, $this->viewer);
        ViewerRelations::flush();

        $this->assertTrue(BlockLookup::ownerBlocksViewer($owner, $this->viewer), 'A block written after the page read its relations was invisible to it.');
    }

    /**
     * One case per arm of every rule a card runs.
     *
     * @return iterable<string, array{InternalCardTarget, Model, bool}>
     */
    private function cases(): iterable
    {
        $stranger = Member::factory()->create();
        $friend = Member::factory()->create();
        $blocker = Member::factory()->create();
        $this->befriend($this->viewer, $friend);
        $this->block($blocker, $this->viewer);

        yield 'my own diary' => [InternalCardTarget::Diary, $this->diaryOf($this->viewer, Visibility::Private), true];
        yield "a stranger's members-only diary" => [InternalCardTarget::Diary, $this->diaryOf($stranger, Visibility::Members), true];
        yield "a stranger's friends-only diary" => [InternalCardTarget::Diary, $this->diaryOf($stranger, Visibility::Friends), false];
        yield "a friend's friends-only diary" => [InternalCardTarget::Diary, $this->diaryOf($friend, Visibility::Friends), true];
        yield "a blocker's members-only diary" => [InternalCardTarget::Diary, $this->diaryOf($blocker, Visibility::Members), false];

        $everyone = Group::factory()->create(['topic_read_access' => TopicReadAccess::Everyone]);
        $membersOnly = Group::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        $joined = Group::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        GroupMember::factory()->create(['group_id' => $joined->id, 'member_id' => $this->viewer->id]);

        yield 'a topic on a board anyone may read' => [InternalCardTarget::Topic, $this->topicIn($everyone, $stranger), true];
        yield 'a topic on a board I am not in' => [InternalCardTarget::Topic, $this->topicIn($membersOnly, $stranger), false];
        yield 'a topic on a board I am in' => [InternalCardTarget::Topic, $this->topicIn($joined, $stranger), true];
        yield 'a message in a room I am not in' => [InternalCardTarget::TalkMessage, $this->messageIn($membersOnly, $stranger), false];
        yield 'a message in a room I am in' => [InternalCardTarget::TalkMessage, $this->messageIn($joined, $stranger), true];

        yield "a stranger's profile" => [InternalCardTarget::Member, $stranger, true];
        yield "a blocker's profile" => [InternalCardTarget::Member, $blocker, false];

        yield "a reply under a friend's thread" => [InternalCardTarget::TimelinePost, $this->replyUnder($friend, $stranger, Visibility::Friends), true];
        yield "a reply under a stranger's friends-only thread" => [InternalCardTarget::TimelinePost, $this->replyUnder($stranger, $friend, Visibility::Friends), false];
    }

    private function diaryOf(Member $author, Visibility $visibility): Diary
    {
        $diary = Diary::factory()->for($author)->create(['visibility' => $visibility]);

        return Diary::with('member')->findOrFail($diary->id);
    }

    private function topicIn(Group $group, Member $author): GroupTopic
    {
        $topic = GroupTopic::factory()->for($group)->for($author)->create();

        return GroupTopic::with('group')->findOrFail($topic->id);
    }

    private function messageIn(Group $group, Member $author): GroupMessage
    {
        $message = GroupMessage::factory()->for($group)->for($author, 'author')->create();

        return GroupMessage::with('group')->findOrFail($message->id);
    }

    /** A reply whose thread root is $rootAuthor's — the author the rule reads, unlike the replier. */
    private function replyUnder(Member $rootAuthor, Member $replier, Visibility $visibility): TimelinePost
    {
        $root = TimelinePost::factory()->for($rootAuthor)->create(['visibility' => $visibility]);
        $reply = TimelinePost::factory()->for($replier)->create([
            'visibility' => $visibility,
            'in_reply_to_id' => $root->id,
        ]);

        return TimelinePost::with(['member', 'parent.member'])->findOrFail($reply->id);
    }

    /**
     * $answer with nothing memoised — the read every rule made before any of this existed.
     *
     * @template T
     *
     * @param  callable(): T  $answer
     * @return T
     */
    private function cold(callable $answer): mixed
    {
        ViewerRelations::flush();

        return $answer();
    }

    /**
     * $answer after $warm has read the page's relations in bulk.
     *
     * @template T
     *
     * @param  callable(ViewerRelations): void  $warm
     * @param  callable(): T  $answer
     * @return T
     */
    private function warm(callable $warm, callable $answer): mixed
    {
        ViewerRelations::flush();
        $warm(app(ViewerRelations::class));

        return $answer();
    }

    /** @return list<string> */
    private function queriesDuring(callable $work): array
    {
        DB::enableQueryLog();
        $work();
        $log = DB::getQueryLog();
        DB::flushQueryLog();
        DB::disableQueryLog();

        return array_map(fn (array $entry): string => (string) $entry['query'], $log);
    }

    private function befriend(Member $a, Member $b): void
    {
        DB::table('friendships')->insert([
            ['member_id' => $a->getKey(), 'friend_id' => $b->getKey()],
            ['member_id' => $b->getKey(), 'friend_id' => $a->getKey()],
        ]);
    }

    private function block(Member $blocker, Member $blocked): void
    {
        DB::table('member_blocks')->insert([
            'blocker_id' => $blocker->getKey(),
            'blocked_id' => $blocked->getKey(),
            'created_at' => now(),
        ]);
    }
}
