<?php

namespace Tests\Feature\GroupEvent;

use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The audit test only checks the declared targets exist; this checks each legacy URL actually lands
 * on its canonical one.
 */
class GroupEventLegacyUrlRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_legacy_urls_redirect_to_their_canonical_shape(): void
    {
        $group = Group::factory()->create();
        $event = GroupEvent::factory()->for($group)->create();
        $comment = GroupEventComment::factory()->for($event, 'event')->create();
        $member = Member::factory()->create();

        $groupId = $group->getKey();
        $eventId = $event->getKey();

        $cases = [
            "/communityEvent/listCommunity/{$groupId}" => "/groups/{$groupId}/events",
            "/communityEvent/new/{$groupId}" => "/groups/{$groupId}/events/new",
            "/communityEvent/{$eventId}" => "/events/{$eventId}",
            "/communityEvent/edit/{$eventId}" => "/events/{$eventId}/edit",
            "/communityEvent/deleteConfirm/{$eventId}" => "/events/{$eventId}/delete",
            "/communityEvent/{$eventId}/memberList" => "/events/{$eventId}/members",
            "/communityEvent/comment/deleteConfirm/{$comment->getKey()}" => "/events/comments/{$comment->getKey()}/delete",
        ];

        foreach ($cases as $legacy => $canonical) {
            $this->actingAs($member)->get($legacy)->assertRedirect($canonical);
        }
    }

    /** The board, the thread and the roster paginate, so a bookmarked ?page=N must not reset to page 1. */
    public function test_the_redirects_carry_the_page_query_along(): void
    {
        $group = Group::factory()->create();
        $event = GroupEvent::factory()->for($group)->create();
        $member = Member::factory()->create();

        $groupId = $group->getKey();
        $eventId = $event->getKey();

        $cases = [
            "/communityEvent/listCommunity/{$groupId}?page=3" => "/groups/{$groupId}/events?page=3",
            "/communityEvent/{$eventId}?page=2&order=asc" => "/events/{$eventId}?page=2&order=asc",
            "/communityEvent/{$eventId}/memberList?page=4" => "/events/{$eventId}/members?page=4",
            // A stray ?event= is consumed by the path parameter (the real id wins), page survives.
            "/communityEvent/{$eventId}?event=999&page=2" => "/events/{$eventId}?page=2",
        ];

        foreach ($cases as $legacy => $canonical) {
            $this->actingAs($member)->get($legacy)->assertRedirect($canonical);
        }
    }
}
