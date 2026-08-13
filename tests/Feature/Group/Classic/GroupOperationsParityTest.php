<?php

namespace Tests\Feature\Group\Classic;

use App\Features\Group\GroupRole;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The OpenPNE 3 community operations: the class-less link list that closes the home page, the
 * join/quit confirmations' preview rows, and the search form's create link.
 */
class GroupOperationsParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_administrator_may_edit_but_neither_join_nor_leave(): void
    {
        $group = Group::factory()->create();
        $admin = $this->joined($group, GroupRole::Admin);

        $response = $this->actingAs($admin)->get(route('group.show', $group))->assertOk();

        $response->assertSee($this->link(route('group.edit', ['id' => $group->getKey()]), 'Edit this group'), false);
        $response->assertDontSee(route('group.quit.show', ['group' => $group->getKey()]), false);
        $response->assertDontSee(route('group.join.show', ['group' => $group->getKey()]), false);
    }

    public function test_the_sub_administrator_may_edit_and_leave(): void
    {
        $group = Group::factory()->create();
        $this->joined($group, GroupRole::Admin);
        $subAdmin = $this->joined($group, GroupRole::SubAdmin);

        $response = $this->actingAs($subAdmin)->get(route('group.show', $group))->assertOk();

        $response->assertSee($this->link(route('group.edit', ['id' => $group->getKey()]), 'Edit this group'), false);
        $response->assertSee($this->link(route('group.quit.show', ['group' => $group->getKey()]), 'Leave this group'), false);
    }

    public function test_a_plain_member_may_only_leave(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);

        $response = $this->actingAs($member)->get(route('group.show', $group))->assertOk();

        $response->assertSee($this->link(route('group.quit.show', ['group' => $group->getKey()]), 'Leave this group'), false);
        $response->assertDontSee(route('group.edit', ['id' => $group->getKey()]), false);
        $response->assertDontSee(route('group.join.show', ['group' => $group->getKey()]), false);
    }

    public function test_an_applicant_gets_the_waiting_notice_but_no_join_link(): void
    {
        // OpenPNE 3 offered Join here too, but its join page rendered an error for an applicant;
        // OpenPNE 4's confirm redirects them straight back, so the link would be a no-op — the
        // Top notice carries the state instead.
        $group = Group::factory()->approval()->create();
        $applicant = Member::factory()->create();
        DB::table('group_join_requests')->insert([
            'group_id' => $group->getKey(),
            'member_id' => $applicant->getKey(),
        ]);

        $response = $this->actingAs($applicant)->get(route('group.show', $group))->assertOk();

        $response->assertDontSee($this->link(route('group.join.show', ['group' => $group->getKey()]), 'Join this group'), false);
        $response->assertSee('waiting for the participation approval', false);
    }

    public function test_a_stranger_only_gets_the_join_link(): void
    {
        $group = Group::factory()->create();

        $response = $this->actingAs(Member::factory()->create())
            ->get(route('group.show', $group))->assertOk();

        $response->assertSee($this->link(route('group.join.show', ['group' => $group->getKey()]), 'Join this group'), false);
        $response->assertDontSee(route('group.quit.show', ['group' => $group->getKey()]), false);
        $response->assertDontSee(route('group.edit', ['id' => $group->getKey()]), false);
    }

    public function test_the_operations_are_a_class_less_list_outside_the_details_box(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);

        $response = $this->actingAs($member)->get(route('group.show', $group))->assertOk();

        // The list closes the details box rather than sitting in its .operation footer.
        $response->assertSeeInOrder([
            'id="communityHome"',
            '</table>',
            '</div>',
            '<ul>',
            route('group.quit.show', ['group' => $group->getKey()]),
        ], false);
        // Roster and delete moved out: the roster lives in the sidemenu, delete inside the editor.
        $response->assertDontSee(route('group.delete.show', $group), false);
    }

    public function test_the_join_confirmation_previews_the_community(): void
    {
        $group = Group::factory()->create(['name' => 'Tokyo Runners']);

        $response = $this->actingAs(Member::factory()->create())
            ->get(route('group.join.show', ['group' => $group->getKey()]))->assertOk();

        $this->assertPreviewRows($response->getContent(), $group);
    }

    public function test_the_quit_confirmation_previews_the_community(): void
    {
        $group = Group::factory()->create(['name' => 'Tokyo Runners']);
        $this->joined($group, GroupRole::Admin);
        $member = $this->joined($group);

        $response = $this->actingAs($member)
            ->get(route('group.quit.show', ['group' => $group->getKey()]))->assertOk();

        $this->assertPreviewRows($response->getContent(), $group);
    }

    public function test_the_search_form_offers_the_create_link(): void
    {
        $response = $this->actingAs(Member::factory()->create())->get(route('group.search'))->assertOk();

        // The parts frame renders a moreInfo option after the form, inside the same box.
        $response->assertSeeInOrder([
            'id="searchCommunity"',
            '</form>',
            '<div class="moreInfo">',
            '<a href="'.route('group.edit').'">Create a new group</a>',
        ], false);
    }

    private function assertPreviewRows(string $html, Group $group): void
    {
        $home = route('group.show', $group);

        $this->assertStringContainsString('<th>Photo</th>', $html);
        // The photo cell links the 76px thumbnail to the community home.
        $this->assertMatchesRegularExpression(
            '#<td><a href="'.preg_quote($home, '#').'"><img [^>]*76[^>]*>\s*</a> </td>#',
            $html
        );
        $this->assertStringContainsString('<th>Group</th>', $html);
        $this->assertStringContainsString('<td><a href="'.$home.'">'.$group->name.'</a></td>', $html);
    }

    public function test_the_operation_labels_render_the_default_ja_wording(): void
    {
        // English-only assertions cannot catch a drifted ja value, so the visible labels are
        // pinned in the OpenPNE 3 sentence shape with the default term (…に参加する / …を退会する / …を削除する).
        $group = Group::factory()->create();
        $stranger = Member::factory()->create();
        $ja = fn ($member) => (string) $this->actingAs($member)->withSession(['locale' => 'ja'])
            ->get(route('group.show', $group))->assertOk()->getContent();

        $this->assertStringContainsString('このグループに参加する', $ja($stranger));
        $this->assertStringContainsString('このグループを退会する', $ja($this->joined($group)));

        $admin = $this->joined($group, GroupRole::Admin);
        $edit = (string) $this->actingAs($admin)->withSession(['locale' => 'ja'])
            ->get(route('group.edit', ['id' => $group->getKey()]))->assertOk()->getContent();
        $this->assertStringContainsString('グループを削除する', $edit);
    }

    private function link(string $url, string $label): string
    {
        return '<li><a href="'.$url.'">'.$label.'</a></li>';
    }

    private function joined(Group $group, GroupRole $role = GroupRole::Member): Member
    {
        $member = Member::factory()->create();
        GroupMember::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
            'role' => $role,
        ]);

        return $member;
    }
}
