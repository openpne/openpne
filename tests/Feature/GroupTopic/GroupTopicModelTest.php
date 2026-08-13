<?php

namespace Tests\Feature\GroupTopic;

use App\Features\GroupTopic\TopicPostAuthority;
use App\Features\GroupTopic\TopicReadAccess;
use App\Models\Group;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroupTopicModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_topic_access_columns_cast_to_enums(): void
    {
        $group = Group::factory()->create([
            'topic_read_access' => TopicReadAccess::MembersOnly,
            'topic_post_authority' => TopicPostAuthority::AdminsOnly,
        ]);

        $group->refresh();
        $this->assertSame(TopicReadAccess::MembersOnly, $group->topic_read_access);
        $this->assertSame(TopicPostAuthority::AdminsOnly, $group->topic_post_authority);
    }

    public function test_topic_access_columns_default_to_the_open_values(): void
    {
        $group = Group::factory()->create();

        $group->refresh();
        $this->assertSame(TopicReadAccess::Everyone, $group->topic_read_access);
        $this->assertSame(TopicPostAuthority::Members, $group->topic_post_authority);
    }

    public function test_topic_updated_at_casts_to_a_datetime(): void
    {
        $topic = GroupTopic::factory()->create(['topic_updated_at' => '2026-06-07 09:00:00']);

        $this->assertTrue($topic->refresh()->topic_updated_at->equalTo('2026-06-07 09:00:00'));
    }

    public function test_relations_resolve(): void
    {
        $group = Group::factory()->create();
        $author = Member::factory()->create();
        $topic = GroupTopic::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $author->getKey(),
        ]);
        $comment = GroupTopicComment::factory()->create([
            'group_topic_id' => $topic->getKey(),
            'member_id' => $author->getKey(),
        ]);

        $this->assertTrue($topic->group->is($group));
        $this->assertTrue($topic->member->is($author));
        $this->assertTrue($topic->comments->first()->is($comment));
        $this->assertTrue($comment->topic->is($topic));
        $this->assertTrue($comment->member->is($author));
        $this->assertTrue($group->topics->first()->is($topic));
    }

    public function test_a_deleted_author_leaves_the_topic_and_comment_intact(): void
    {
        $author = Member::factory()->create();
        $topic = GroupTopic::factory()->create(['member_id' => $author->getKey()]);
        $comment = GroupTopicComment::factory()->create([
            'group_topic_id' => $topic->getKey(),
            'member_id' => $author->getKey(),
        ]);

        $author->delete();

        $this->assertNull($topic->refresh()->member_id);
        $this->assertNull($comment->refresh()->member_id);
    }

    public function test_deleting_a_topic_cascades_to_its_comments(): void
    {
        $topic = GroupTopic::factory()->create();
        GroupTopicComment::factory()->create(['group_topic_id' => $topic->getKey()]);

        $topic->delete();

        $this->assertSame(0, GroupTopicComment::query()->count());
    }
}
