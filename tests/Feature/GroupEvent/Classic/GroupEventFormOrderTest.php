<?php

namespace Tests\Feature\GroupEvent\Classic;

use App\Features\Group\GroupRole;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OpenPNE 3's event form (PluginCommunityEventForm) ran title, body, open date, its supplement on a
 * row of its own, area, deadline, capacity, then the three photo rows.
 */
class GroupEventFormOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_create_form_keeps_the_openpne3_field_order_and_photo_rows(): void
    {
        $group = Group::factory()->create();
        $member = Member::factory()->create();
        GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey(), 'role' => GroupRole::Member]);

        $response = $this->actingAs($member)->get(route('group.events.new', $group));

        $response->assertOk();
        $response->assertSeeInOrder([
            'for="event_name"', 'for="event_body"', 'for="event_open_date"',
            'for="event_open_date_comment">Open date comment</label>',
            'for="event_area"', 'for="event_application_deadline"', 'for="event_capacity"',
            '<ul id="community_event_photo_1">', '<ul id="community_event_photo_2">', '<ul id="community_event_photo_3">',
        ], false);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*name="open_date_comment"[^>]*placeholder=/', (string) $response->getContent());
    }
}
