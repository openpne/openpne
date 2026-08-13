<?php

namespace Tests\Feature\GroupTopic\Actions;

use App\Features\Group\GroupRole;
use App\Features\GroupTopic\Actions\CreateTopic;
use App\Features\GroupTopic\Actions\CreateTopicComment;
use App\Features\GroupTopic\Actions\DeleteTopic;
use App\Features\GroupTopic\Actions\DeleteTopicComment;
use App\Features\GroupTopic\Actions\UpdateTopic;
use App\Features\GroupTopic\Data\GroupTopicFormData;
use App\Features\GroupTopic\Exceptions\GroupTopicActionException;
use App\Features\GroupTopic\Exceptions\GroupTopicActionFailure;
use App\Features\GroupTopic\TopicPostAuthority;
use App\Files\ImageEdit;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use App\Models\Member;
use App\Support\BodyFormat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TopicActionsTest extends TestCase
{
    use RefreshDatabase;

    private function joined(Group $group, GroupRole $role): Member
    {
        $member = Member::factory()->create();
        GroupMember::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
            'role' => $role,
        ]);

        return $member;
    }

    private function assertFails(callable $run, GroupTopicActionFailure $reason): void
    {
        try {
            $run();
            $this->fail("expected GroupTopicActionException [{$reason->value}]");
        } catch (GroupTopicActionException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    public function test_create_topic_sets_the_author_and_activity_timestamp(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group, GroupRole::Member);

        $topic = app(CreateTopic::class)($author, $group, new GroupTopicFormData('Welcome', 'Say hi here.'));

        $this->assertSame($group->getKey(), $topic->group_id);
        $this->assertSame($author->getKey(), $topic->member_id);
        $this->assertNotNull($topic->topic_updated_at);
    }

    public function test_create_topic_is_blocked_when_posting_is_admin_only(): void
    {
        $group = Group::factory()->create(['topic_post_authority' => TopicPostAuthority::AdminsOnly]);
        $member = $this->joined($group, GroupRole::Member);

        $this->assertFails(
            fn () => app(CreateTopic::class)($member, $group, new GroupTopicFormData('No', 'Nope.')),
            GroupTopicActionFailure::CannotPost,
        );
    }

    public function test_update_topic_bumps_timestamps_only_on_a_content_change(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group, GroupRole::Member);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);
        DB::table('group_topics')->where('id', $topic->getKey())->update([
            'updated_at' => now()->subDay(),
            'topic_updated_at' => now()->subDay(),
        ]);

        // No-op edit (same content) does not touch the timestamps.
        app(UpdateTopic::class)($author, $topic->fresh(), new GroupTopicFormData($topic->name, $topic->body), ImageEdit::none());
        $this->assertTrue($topic->fresh()->updated_at->lessThan(now()->subHour()));

        // A real edit bumps both updated_at (board key) and topic_updated_at.
        app(UpdateTopic::class)($author, $topic->fresh(), new GroupTopicFormData('Edited', $topic->body), ImageEdit::none());
        $fresh = $topic->fresh();
        $this->assertSame('Edited', $fresh->name);
        $this->assertTrue($fresh->updated_at->greaterThan(now()->subMinute()));
        $this->assertTrue($fresh->topic_updated_at->greaterThan(now()->subMinute()));
    }

    public function test_update_topic_cannot_change_an_op3_rows_format(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group, GroupRole::Member);
        $topic = GroupTopic::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $author->getKey(),
            'format' => BodyFormat::Op3,
        ]);

        app(UpdateTopic::class)($author, $topic, new GroupTopicFormData('Edited', 'b', BodyFormat::Markdown), ImageEdit::none());

        $this->assertSame(BodyFormat::Op3, $topic->fresh()->format);
    }

    public function test_update_topic_is_blocked_for_a_non_author_non_admin(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group, GroupRole::Member);
        $other = $this->joined($group, GroupRole::Member);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);

        $this->assertFails(
            fn () => app(UpdateTopic::class)($other, $topic, new GroupTopicFormData('Hijack', 'No.'), ImageEdit::none()),
            GroupTopicActionFailure::CannotEdit,
        );
    }

    public function test_delete_topic_removes_it_and_cascades_comments(): void
    {
        $group = Group::factory()->create();
        $admin = $this->joined($group, GroupRole::Admin);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $admin->getKey()]);
        GroupTopicComment::factory()->create(['group_topic_id' => $topic->getKey()]);

        (new DeleteTopic)($admin, $topic);

        $this->assertDatabaseMissing('group_topics', ['id' => $topic->getKey()]);
        $this->assertSame(0, GroupTopicComment::query()->count());
    }

    public function test_comments_are_numbered_per_topic_and_lift_the_board_timestamp(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group, GroupRole::Member);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);
        DB::table('group_topics')->where('id', $topic->getKey())->update(['updated_at' => now()->subDay()]);

        $first = app(CreateTopicComment::class)($author, $topic, 'one');
        $second = app(CreateTopicComment::class)($author, $topic, 'two');
        $third = app(CreateTopicComment::class)($author, $topic, 'three');

        $this->assertSame([1, 2, 3], [$first->number, $second->number, $third->number]);
        // A new comment lifts the topic on the board.
        $this->assertTrue($topic->fresh()->updated_at->greaterThan(now()->subMinute()));
        $this->assertTrue($topic->fresh()->topic_updated_at->greaterThan(now()->subMinute()));
    }

    public function test_commenting_is_blocked_for_a_non_member(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group, GroupRole::Member);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);
        $stranger = Member::factory()->create();

        $this->assertFails(
            fn () => app(CreateTopicComment::class)($stranger, $topic, 'intruding'),
            GroupTopicActionFailure::CannotComment,
        );
    }

    public function test_delete_comment_is_blocked_for_an_unrelated_member(): void
    {
        $group = Group::factory()->create();
        $commenter = $this->joined($group, GroupRole::Member);
        $other = $this->joined($group, GroupRole::Member);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey()]);
        $comment = GroupTopicComment::factory()->create([
            'group_topic_id' => $topic->getKey(),
            'member_id' => $commenter->getKey(),
        ]);

        $this->assertFails(
            fn () => (new DeleteTopicComment)($other, $comment),
            GroupTopicActionFailure::CannotDeleteComment,
        );

        (new DeleteTopicComment)($commenter, $comment);
        $this->assertDatabaseMissing('group_topic_comments', ['id' => $comment->getKey()]);
    }
}
