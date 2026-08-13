<?php

namespace Tests\Feature\Group\Classic;

use App\Features\CommunityTopic\TopicPostAuthority;
use App\Features\CommunityTopic\TopicReadAccess;
use App\Models\CommunityEvent;
use App\Models\CommunityTopic;
use App\Models\Group;
use App\Models\GroupCategory;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OpenPNE 3 community home fidelity: the sidemenu member grid crowns the admin, and the center
 * column renders the full details listBox. Labels resolve in the request locale (en here), so the
 * assertions are the rendered English output (the fronted %Community% term renders "Community").
 */
class GroupHomeDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_crown_icon_is_vendored(): void
    {
        $this->assertFileExists(public_path('images/icon_crown.gif'));
    }

    public function test_the_admin_gets_a_crown_but_the_sub_admin_does_not(): void
    {
        $group = Group::factory()->create();
        $admin = Member::factory()->create(['name' => 'AdminAlice']);
        $sub = Member::factory()->create(['name' => 'SubBob']);
        $plain = Member::factory()->create(['name' => 'MemberCarol']);
        GroupMember::factory()->admin()->create(['group_id' => $group->id, 'member_id' => $admin->id]);
        GroupMember::factory()->subAdmin()->create(['group_id' => $group->id, 'member_id' => $sub->id]);
        GroupMember::factory()->member()->create(['group_id' => $group->id, 'member_id' => $plain->id]);

        $response = $this->actingAs($plain)->get(route('group.show', $group))->assertOk();

        $crown = '<p class="crown"><img src="'.asset('images/icon_crown.gif').'" alt="admin"></p>';
        // The crown sits in the admin's photo cell, immediately before the admin's grid link
        // (adjacency, so a crown on some other cell cannot satisfy this via a later admin link).
        $this->assertMatchesRegularExpression(
            '#'.preg_quote($crown, '#').'\s*<a href="'.preg_quote(route('member.profile.show', $admin), '#').'"#',
            $response->getContent()
        );
        // Exactly one crown across the page — the sub-admin and plain member cells carry none.
        $this->assertSame(1, substr_count($response->getContent(), '<p class="crown">'));
    }

    public function test_the_details_list_box_renders_the_op3_rows_in_order(): void
    {
        $category = GroupCategory::factory()->create(['name' => 'Sports']);
        $group = Group::factory()->approval()->create([
            'name' => 'Tokyo Runners',
            'description' => 'Hello world',
            'group_category_id' => $category->getKey(),
            'created_at' => '2026-05-01 12:00:00',
        ]);
        $admin = Member::factory()->create(['name' => 'AdminAlice']);
        $sub = Member::factory()->create(['name' => 'SubBob']);
        GroupMember::factory()->admin()->create(['group_id' => $group->id, 'member_id' => $admin->id]);
        GroupMember::factory()->subAdmin()->create(['group_id' => $group->id, 'member_id' => $sub->id]);

        $response = $this->actingAs($admin)->get(route('group.show', $group))->assertOk();

        // The box heading is the fronted %Community% term, and the th labels follow OpenPNE 3's
        // rendered order. The config rows (topic access, register policy, description) come from
        // OpenPNE 3's community-config registry, so the order is pinned from a live render, not
        // the template source.
        $response->assertSeeInOrder(['id="communityHome"', '<h3>Community</h3>'], false);
        $response->assertSeeInOrder([
            '<th>Community Name</th>',
            '<th>Community Category</th>',
            '<th>Date Created</th>',
            '<th>Administrator</th>',
            '<th>Sub Administrator</th>',
            '<th>Count of Members</th>',
            '<th>Authority to Read Topic</th>',
            '<th>Authority to Create Topic</th>',
            '<th>Register policy</th>',
            '<th>Community Description</th>',
        ], false);

        // The Date Created row renders the localized date (en LL format).
        $response->assertSee('<td>May 1, 2026</td>', false);
        // The Administrator and Sub Administrator rows link to the members' profiles.
        $response->assertSee('<td><a href="'.route('member.profile.show', $admin).'">AdminAlice</a></td>', false);
        $response->assertSee('<li><a href="'.route('member.profile.show', $sub).'">SubBob</a></li>', false);
        // The category name and the enum value labels render.
        $response->assertSee('Sports');
        $response->assertSee('Approval required'); // register_policy = Approval
        $response->assertSee('Everyone can read');   // topic_read_access default, OpenPNE 3's own caption
        $response->assertSee(e(__("%Community%'s members can create")), false);  // topic_post_authority default, OpenPNE 3's own caption
    }

    public function test_the_sub_administrator_row_is_absent_without_a_sub_admin(): void
    {
        $group = Group::factory()->create();
        $admin = Member::factory()->create(['name' => 'AdminAlice']);
        $plain = Member::factory()->create(['name' => 'MemberCarol']);
        GroupMember::factory()->admin()->create(['group_id' => $group->id, 'member_id' => $admin->id]);
        GroupMember::factory()->member()->create(['group_id' => $group->id, 'member_id' => $plain->id]);

        $this->actingAs($plain)->get(route('group.show', $group))
            ->assertOk()
            ->assertSee('<th>Administrator</th>', false)
            ->assertDontSee('<th>Sub Administrator</th>', false);
    }

    public function test_a_community_without_memberships_renders_without_an_administrator_row(): void
    {
        // Group::factory() creates no admin membership, so adminMember is null.
        $group = Group::factory()->create();

        $this->actingAs(Member::factory()->create())->get(route('group.show', $group))
            ->assertOk()
            ->assertSee('id="communityHome"', false)
            ->assertDontSee('<th>Administrator</th>', false);
    }

    public function test_the_sidemenu_image_box_is_op3_sized_and_captioned(): void
    {
        $group = Group::factory()->create(['name' => 'Tokyo Walkers']);
        $member = Member::factory()->create();
        GroupMember::factory()->member()->create(['group_id' => $group->id, 'member_id' => $member->id]);

        $response = $this->actingAs($member)->get(route('group.show', $group))->assertOk();

        // OpenPNE 3 draws the community photo at 180×180 and captions it getNameAndCount().
        $response->assertSee('<img src="'.asset('images/no_image.gif').'" width="180" height="180" alt="Tokyo Walkers">', false);
        $response->assertSee('<p class="text">Tokyo Walkers (1)</p>', false);
    }

    /**
     * The OpenPNE 3 recent-event and recent-topic rows close the details table (the communityTopic
     * plugin's lastRow customize, events first): update date + 36-width title with comment count,
     * links in the row's own moreInfo list.
     */
    public function test_the_recent_event_and_topic_rows_close_the_details_table(): void
    {
        $group = Group::factory()->create();
        $member = Member::factory()->create();
        GroupMember::factory()->member()->create(['group_id' => $group->id, 'member_id' => $member->id]);
        $topic = CommunityTopic::factory()->create(['community_id' => $group->id, 'name' => 'Walk plans', 'member_id' => $member->id]);
        $topic->timestamps = false;
        $topic->forceFill(['updated_at' => '2026-07-08 12:00:00'])->save();
        $event = CommunityEvent::factory()->create(['community_id' => $group->id, 'name' => 'Summer stroll', 'member_id' => $member->id]);
        $event->timestamps = false;
        $event->forceFill(['updated_at' => '2026-06-11 12:00:00'])->save();

        $response = $this->actingAs($member)->get(route('group.show', $group))->assertOk();

        // Events first, after the description row, inside the same table.
        $response->assertSeeInOrder([
            '<th>Community Description</th>',
            '<tr class="communityEvent">',
            '<th>Community Events</th>',
            '<tr class="communityTopic">',
            '<th>Community Topics</th>',
            '</table>',
        ], false);
        // Row shape: <span class="date">{update date}</span> <a>{title}({count})</a> — no space
        // before the count, and the event's date is its update date, not its open date.
        $response->assertSee('<li><span class="date">June 11</span> <a href="'.route('communityEvent.show', $event).'">Summer stroll(0)</a></li>', false);
        $response->assertSee('<li><span class="date">July 8</span> <a href="'.route('communityTopic.show', $topic).'">Walk plans(0)</a></li>', false);
        // Each row carries More (the board) and the create link in its own moreInfo list.
        $response->assertSeeInOrder([
            '<tr class="communityTopic">',
            '<div class="moreInfo">',
            route('communityTopic.index', $group),
            route('communityTopic.new', $group),
        ], false);
    }

    public function test_an_empty_board_row_keeps_the_create_link_but_drops_more(): void
    {
        $group = Group::factory()->create();
        $member = Member::factory()->create();
        GroupMember::factory()->member()->create(['group_id' => $group->id, 'member_id' => $member->id]);

        $response = $this->actingAs($member)->get(route('group.show', $group))->assertOk();

        // OpenPNE 3 renders no list, no message and no More over an empty board — the row stays
        // for its create link.
        $response->assertSee('<tr class="communityTopic">', false);
        $response->assertDontSee('articleList', false);
        $response->assertDontSee('No topics to show.', false);
        $response->assertDontSee('>More<', false);
        $response->assertSee(route('communityTopic.new', $group), false);
    }

    public function test_a_viewer_without_post_authority_gets_no_create_link(): void
    {
        $group = Group::factory()->create(['topic_post_authority' => TopicPostAuthority::AdminsOnly]);
        $member = Member::factory()->create();
        GroupMember::factory()->member()->create(['group_id' => $group->id, 'member_id' => $member->id]);
        CommunityTopic::factory()->create(['community_id' => $group->id, 'name' => 'Members read this', 'member_id' => $member->id]);

        $response = $this->actingAs($member)->get(route('group.show', $group))->assertOk();

        $response->assertSee('<tr class="communityTopic">', false);
        $response->assertSee(route('communityTopic.index', $group), false);
        $response->assertDontSee(route('communityTopic.new', $group), false);
    }

    public function test_a_members_only_board_renders_no_rows_for_outsiders(): void
    {
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        $member = Member::factory()->create();
        GroupMember::factory()->member()->create(['group_id' => $group->id, 'member_id' => $member->id]);
        CommunityTopic::factory()->create(['community_id' => $group->id, 'name' => 'Members read this', 'member_id' => $member->id]);

        // OpenPNE 3 renders the whole row inside the view ACL: an outsider gets neither row.
        $this->actingAs(Member::factory()->create())->get(route('group.show', $group))
            ->assertOk()
            ->assertDontSee('<tr class="communityTopic">', false)
            ->assertDontSee('<tr class="communityEvent">', false);
    }
}
