<?php

namespace Tests\Feature\Group;

use App\Models\Group;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The OpenPNE 3 `/community/*` GET URLs, preserved by redirect now that the canonical space is
 * `/groups/*` (GroupRouteParity::compatRedirects()). The audit test only checks the declared
 * targets exist; this checks each legacy URL actually lands on its canonical one, and that the id
 * OpenPNE 3 carried as `?id=` reaches the path.
 */
class GroupLegacyUrlRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_legacy_urls_redirect_to_their_canonical_shape(): void
    {
        $group = Group::factory()->create();
        $id = $group->getKey();
        $member = Member::factory()->create();

        $cases = [
            '/community/search' => '/groups',
            '/community/joinList' => '/groups/mine',
            '/community/edit' => '/groups/edit',
            "/community/edit?id={$id}" => "/groups/edit?id={$id}",
            "/community/{$id}" => "/groups/{$id}",
            "/community/join?id={$id}" => "/groups/{$id}/join",
            "/community/quit?id={$id}" => "/groups/{$id}/quit",
            "/community/delete/{$id}" => "/groups/{$id}/delete",
            "/community/member/list?id={$id}" => "/groups/{$id}/members",
            "/community/member/pending?id={$id}" => "/groups/{$id}/members/pending",
            "/community/member/manage/{$id}" => "/groups/{$id}/members/manage",
            "/community/{$id}/timeline" => "/groups/{$id}/timeline",
            "/timeline/community/id/{$id}" => "/groups/{$id}/timeline",
        ];

        foreach ($cases as $legacy => $canonical) {
            $this->actingAs($member)->get($legacy)->assertRedirect($canonical);
        }
    }

    /**
     * The OpenPNE 3 search query shape must survive the redirect — GroupController still accepts
     * it, so dropping it would turn a bookmarked search into the unfiltered list.
     */
    public function test_the_search_and_recent_redirects_carry_the_query_along(): void
    {
        $member = Member::factory()->create();

        $location = $this->actingAs($member)
            ->get('/community/search?community%5Bname%5D=hiking&community%5Bcommunity_category_id%5D=2&search_query=alps&page=3')
            ->headers->get('Location');
        $this->assertSame('/groups', parse_url($location, PHP_URL_PATH));
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame([
            'community' => ['name' => 'hiking', 'community_category_id' => '2'],
            'search_query' => 'alps',
            'page' => '3',
        ], $query);

        $this->actingAs($member)->get('/community/recent?page=2')->assertRedirect('/groups/recent?page=2');
    }

    /** The per-member operations carried `member_id` alongside `?id=`; only the id moves. */
    public function test_a_member_operation_keeps_its_target_in_the_query(): void
    {
        $group = Group::factory()->create();
        $member = Member::factory()->create();

        $this->actingAs($member)
            ->get("/community/member/appointSubAdmin?id={$group->getKey()}&member_id=7")
            ->assertRedirect("/groups/{$group->getKey()}/members/appoint?member_id=7");
    }

    /** Without the id there is no group to redirect to, so the legacy URL 404s rather than guess. */
    public function test_an_id_less_legacy_url_that_needs_one_is_not_found(): void
    {
        $this->actingAs(Member::factory()->create())->get('/community/join')->assertNotFound();
    }
}
