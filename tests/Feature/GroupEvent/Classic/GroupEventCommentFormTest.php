<?php

namespace Tests\Feature\GroupEvent\Classic;

use App\Features\Group\GroupRole;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** OpenPNE 3 named the comment box and its three photo rows after the CommunityEventComment form. */
class GroupEventCommentFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_comment_form_names_its_box_and_photo_rows_as_openpne3_did(): void
    {
        $group = Group::factory()->create();
        $member = Member::factory()->create();
        GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey(), 'role' => GroupRole::Member]);
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey()]);

        $response = $this->actingAs($member)->get(route('group.events.show', $event));

        $response->assertOk();
        $response->assertSeeInOrder([
            '<label for="community_event_comment_body">Comment <strong>*</strong>',
            '<textarea id="community_event_comment_body" name="body"',
            '<label for="community_event_comment_photo_1_photo">Photo 1</label>',
            '<ul id="community_event_comment_photo_1">',
            '<input type="file" name="images[]" id="community_event_comment_photo_1_photo"',
            '<ul id="community_event_comment_photo_2">',
            '<ul id="community_event_comment_photo_3">',
        ], false);
    }
}
