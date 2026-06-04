<?php

namespace Tests\Feature\Diary\Classic;

use App\Models\Diary;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks OpenPNE 3's diary LayoutB sidemenu: a screen that opts into it renders the two-column
 * layout (id="LayoutB" + id="Left") with the author box and recent-diaries list, while a diary
 * screen that does not opt in keeps the single-column LayoutC. Anchors are markup hooks/routes.
 */
class DiarySidemenuParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_renders_the_layoutb_left_sidemenu_with_author_and_recent_diaries(): void
    {
        $owner = Member::factory()->create();
        $recent = Diary::factory()->create(['member_id' => $owner->getKey(), 'title' => 'Sidemenu Recent Entry']);
        $current = Diary::factory()->create(['member_id' => $owner->getKey(), 'title' => 'Current Entry']);

        $response = $this->actingAs($owner)->get("/diary/{$current->getKey()}");

        $response->assertOk();
        $response->assertSee('id="LayoutB"', false);                                    // two-column layout
        $response->assertSee('id="Left"', false);                                       // sidemenu slot
        $response->assertSee('class="parts memberImageBox"', false);                    // OpenPNE 3 hook
        $response->assertSee('href="'.route('member.profile.show', $owner).'"', false); // author profile link
        $response->assertSee('Recently Posted');                                        // recent box heading
        $response->assertSee('href="'.route('diary.show', $recent).'"', false);         // recent entry link
        $response->assertSee('Sidemenu Recent Entry (0)');                              // title + comment count
    }

    public function test_listmember_opts_into_the_sidemenu(): void
    {
        $owner = Member::factory()->create();
        Diary::factory()->create(['member_id' => $owner->getKey()]);

        $this->actingAs($owner)->get('/diary/listMember')
            ->assertOk()
            ->assertSee('id="LayoutB"', false)
            ->assertSee('id="Left"', false);
    }

    public function test_recent_box_is_scoped_to_the_viewer(): void
    {
        [$owner, $other] = Member::factory()->count(2)->create()->all();
        Diary::factory()->create(['member_id' => $owner->getKey(), 'visibility' => Visibility::Members, 'title' => 'Members Visible']);
        Diary::factory()->private()->create(['member_id' => $owner->getKey(), 'title' => 'Private Hidden']);

        $this->actingAs($other)->get("/diary/listMember/{$owner->getKey()}")
            ->assertOk()
            ->assertSee('Members Visible')
            ->assertDontSee('Private Hidden'); // not in the sidemenu recent box for a non-friend
    }

    public function test_all_member_feed_without_a_sidemenu_stays_single_column(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/diary/list')
            ->assertOk()
            ->assertSee('id="LayoutC"', false)
            ->assertDontSee('id="Left"', false);
    }
}
