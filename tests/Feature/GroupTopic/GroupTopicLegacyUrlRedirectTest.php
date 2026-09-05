<?php

namespace Tests\Feature\GroupTopic;

use App\Models\Group;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The audit test only checks the declared targets exist; this checks each legacy URL actually lands
 * on its canonical one.
 */
class GroupTopicLegacyUrlRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_legacy_urls_redirect_to_their_canonical_shape(): void
    {
        $group = Group::factory()->create();
        $topic = GroupTopic::factory()->for($group)->create();
        $comment = GroupTopicComment::factory()->for($topic, 'topic')->create();
        $member = Member::factory()->create();

        $groupId = $group->getKey();
        $topicId = $topic->getKey();

        $cases = [
            "/communityTopic/listCommunity/{$groupId}" => "/groups/{$groupId}/topics",
            "/communityTopic/new/{$groupId}" => "/groups/{$groupId}/topics/new",
            "/communityTopic/{$topicId}" => "/topics/{$topicId}",
            "/communityTopic/edit/{$topicId}" => "/topics/{$topicId}/edit",
            "/communityTopic/deleteConfirm/{$topicId}" => "/topics/{$topicId}/delete",
            "/communityTopic/comment/deleteConfirm/{$comment->getKey()}" => "/topics/comments/{$comment->getKey()}/delete",
        ];

        foreach ($cases as $legacy => $canonical) {
            $this->actingAs($member)->get($legacy)->assertRedirect($canonical);
        }
    }

    /** The board and the thread paginate, so a bookmarked ?page=N must not reset to page 1. */
    public function test_the_redirects_carry_the_page_query_along(): void
    {
        $group = Group::factory()->create();
        $topic = GroupTopic::factory()->for($group)->create();
        $member = Member::factory()->create();

        $groupId = $group->getKey();
        $topicId = $topic->getKey();

        $cases = [
            "/communityTopic/listCommunity/{$groupId}?page=3" => "/groups/{$groupId}/topics?page=3",
            "/communityTopic/{$topicId}?page=2&order=asc" => "/topics/{$topicId}?page=2&order=asc",
            // A stray ?topic= is consumed by the path parameter (the real id wins), page survives.
            "/communityTopic/{$topicId}?topic=999&page=2" => "/topics/{$topicId}?page=2",
        ];

        foreach ($cases as $legacy => $canonical) {
            $this->actingAs($member)->get($legacy)->assertRedirect($canonical);
        }
    }
}
