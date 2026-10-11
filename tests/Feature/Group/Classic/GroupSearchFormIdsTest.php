<?php

namespace Tests\Feature\Group\Classic;

use App\Models\GroupCategory;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** OpenPNE 3's CommunityFormFilter (name format community[%s]) gave the search fields their ids. */
class GroupSearchFormIdsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_search_form_keeps_the_community_filter_ids(): void
    {
        GroupCategory::factory()->create();

        $response = $this->actingAs(Member::factory()->create())->get(route('group.search'));

        $response->assertOk();
        $response->assertSeeInOrder([
            '<label for="community_name">',
            '<input type="text" class="input_text" id="community_name" name="community[name]"',
            '<label for="community_community_category_id">',
            '<select id="community_community_category_id" name="community[community_category_id]">',
        ], false);
    }
}
