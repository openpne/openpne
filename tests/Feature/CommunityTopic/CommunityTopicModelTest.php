<?php

namespace Tests\Feature\CommunityTopic;

use App\Features\CommunityTopic\TopicPostAuthority;
use App\Features\CommunityTopic\TopicReadAccess;
use App\Models\Community;
use App\Models\CommunityTopic;
use App\Models\CommunityTopicComment;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunityTopicModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_topic_access_columns_cast_to_enums(): void
    {
        $community = Community::factory()->create([
            'topic_read_access' => TopicReadAccess::MembersOnly,
            'topic_post_authority' => TopicPostAuthority::AdminsOnly,
        ]);

        $community->refresh();
        $this->assertSame(TopicReadAccess::MembersOnly, $community->topic_read_access);
        $this->assertSame(TopicPostAuthority::AdminsOnly, $community->topic_post_authority);
    }

    public function test_topic_access_columns_default_to_the_open_values(): void
    {
        $community = Community::factory()->create();

        $community->refresh();
        $this->assertSame(TopicReadAccess::Everyone, $community->topic_read_access);
        $this->assertSame(TopicPostAuthority::Members, $community->topic_post_authority);
    }

    public function test_topic_updated_at_casts_to_a_datetime(): void
    {
        $topic = CommunityTopic::factory()->create(['topic_updated_at' => '2026-06-07 09:00:00']);

        $this->assertTrue($topic->refresh()->topic_updated_at->equalTo('2026-06-07 09:00:00'));
    }

    public function test_relations_resolve(): void
    {
        $community = Community::factory()->create();
        $author = Member::factory()->create();
        $topic = CommunityTopic::factory()->create([
            'community_id' => $community->getKey(),
            'member_id' => $author->getKey(),
        ]);
        $comment = CommunityTopicComment::factory()->create([
            'community_topic_id' => $topic->getKey(),
            'member_id' => $author->getKey(),
        ]);

        $this->assertTrue($topic->community->is($community));
        $this->assertTrue($topic->member->is($author));
        $this->assertTrue($topic->comments->first()->is($comment));
        $this->assertTrue($comment->topic->is($topic));
        $this->assertTrue($comment->member->is($author));
        $this->assertTrue($community->topics->first()->is($topic));
    }

    public function test_a_deleted_author_leaves_the_topic_and_comment_intact(): void
    {
        $author = Member::factory()->create();
        $topic = CommunityTopic::factory()->create(['member_id' => $author->getKey()]);
        $comment = CommunityTopicComment::factory()->create([
            'community_topic_id' => $topic->getKey(),
            'member_id' => $author->getKey(),
        ]);

        $author->delete();

        $this->assertNull($topic->refresh()->member_id);
        $this->assertNull($comment->refresh()->member_id);
    }

    public function test_deleting_a_topic_cascades_to_its_comments(): void
    {
        $topic = CommunityTopic::factory()->create();
        CommunityTopicComment::factory()->create(['community_topic_id' => $topic->getKey()]);

        $topic->delete();

        $this->assertSame(0, CommunityTopicComment::query()->count());
    }
}
