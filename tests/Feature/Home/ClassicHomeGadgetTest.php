<?php

namespace Tests\Feature\Home;

use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Gadget;
use App\Models\GadgetConfig;
use App\Models\Member;
use App\Services\GadgetService;
use App\Services\SnsSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClassicHomeGadgetTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, string> $config */
    private function makeGadget(string $context, string $zone, string $name, array $config = []): Gadget
    {
        $gadget = Gadget::create(['context' => $context, 'zone' => $zone, 'name' => $name, 'sort_order' => 0]);
        foreach ($config as $key => $value) {
            GadgetConfig::create(['gadget_id' => $gadget->id, 'name' => $key, 'value' => $value]);
        }
        app(GadgetService::class)->clearCache();

        return $gadget;
    }

    public function test_renders_a_configured_gadget_instead_of_the_fallback(): void
    {
        $member = Member::factory()->create();
        $gadget = $this->makeGadget('home', 'contents', 'freeArea', ['title' => 'Notice Board', 'value' => '<p>FreeBody</p>']);

        $this->actingAs($member)->get('/')
            ->assertOk()
            ->assertSee('id="freeArea_'.$gadget->id.'"', false) // kind-scoped OpenPNE 3 DOM id
            ->assertSee('Notice Board')
            ->assertSee('<p>FreeBody</p>', false) // trusted HTML rendered unescaped
            ->assertDontSee('id="home_index"', false); // the empty-state fallback is gone
    }

    public function test_sidemenu_zone_renders_the_left_column(): void
    {
        $member = Member::factory()->create(['name' => 'Hanako']);
        $gadget = $this->makeGadget('home', 'sideMenu', 'memberImageBox');

        // Default home layout is layoutA: a sideMenu gadget fills #Left while the empty top zone just
        // omits its div. The letter stays A from the setting (OpenPNE 3 setLayout), not B from content.
        $this->actingAs($member)->get('/')
            ->assertOk()
            ->assertSee('id="LayoutA"', false)
            ->assertDontSee('id="LayoutB"', false)
            ->assertSee('id="Left"', false)
            ->assertSee('id="memberImageBox_'.$gadget->id.'"', false)
            ->assertSee('Hanako');
    }

    public function test_top_zone_renders_layout_a(): void
    {
        $member = Member::factory()->create();
        $this->makeGadget('home', 'top', 'informationBox', ['value' => '<p>TopNews</p>']);

        $this->actingAs($member)->get('/')
            ->assertOk()
            ->assertSee('id="LayoutA"', false)
            ->assertSee('id="Top"', false)
            ->assertSee('<p>TopNews</p>', false);
    }

    public function test_data_driven_list_boxes_render_the_openpne3_nine_table_grid(): void
    {
        $member = Member::factory()->create();
        $friend = Member::factory()->create(['name' => 'AlphaFriend']);
        DB::table('friendships')->insert([
            ['member_id' => $member->id, 'friend_id' => $friend->id],
            ['member_id' => $friend->id, 'friend_id' => $member->id],
        ]);
        $community = Community::factory()->create(['name' => 'BetaCommunity']);
        CommunityMember::factory()->create(['community_id' => $community->id, 'member_id' => $member->id]);

        $friends = $this->makeGadget('home', 'sideMenu', 'friendListBox');
        $communities = $this->makeGadget('home', 'sideMenu', 'communityJoinListBox');

        $this->actingAs($member)->get('/')
            ->assertOk()
            ->assertSee('id="friendList_'.$friends->id.'"', false)
            ->assertSee('id="communityList_'.$communities->id.'"', false)
            ->assertSee('class="dparts nineTable"', false) // skin targets .nineTable tr.photo td
            ->assertSee('AlphaFriend')
            ->assertSee('BetaCommunity');
    }

    public function test_empty_list_box_is_dropped_like_openpne3(): void
    {
        $member = Member::factory()->create();
        $this->makeGadget('home', 'sideMenu', 'friendListBox');

        // No friends: OpenPNE 3 drops the whole box rather than render an orphan heading.
        $this->actingAs($member)->get('/')
            ->assertOk()
            ->assertDontSee('nineTable', false);
    }

    public function test_wrapper_carries_the_openpne3_parts_name_class(): void
    {
        $member = Member::factory()->create();
        $this->makeGadget('home', 'contents', 'freeArea', ['value' => '<p>x</p>']);   // box, dparts
        $this->makeGadget('home', 'top', 'informationBox', ['value' => '<p>y</p>']);  // single parts

        $this->actingAs($member)->get('/')
            ->assertOk()
            ->assertSee('class="dparts box"', false)
            ->assertSee('class="parts informationBox"', false); // single drops the inner div.parts
    }

    public function test_list_box_ignores_the_host_pages_page_query(): void
    {
        $member = Member::factory()->create();
        $friend = Member::factory()->create(['name' => 'PageOneFriend']);
        DB::table('friendships')->insert([
            ['member_id' => $member->id, 'friend_id' => $friend->id],
            ['member_id' => $friend->id, 'friend_id' => $member->id],
        ]);
        $this->makeGadget('home', 'sideMenu', 'friendListBox');

        // A ?page= on the host page must not paginate the gadget's list away.
        $this->actingAs($member)->get('/?page=2')
            ->assertOk()
            ->assertSee('PageOneFriend');
    }

    public function test_active_layout_narrows_the_rendered_zones(): void
    {
        DB::table('sns_settings')->insert(['key' => 'gadget_home_layout', 'value' => 'layoutC']);
        app(SnsSettingService::class)->clearCache();

        $member = Member::factory()->create();
        $this->makeGadget('home', 'top', 'informationBox', ['value' => '<p>TopNews</p>']);
        $this->makeGadget('home', 'contents', 'freeArea', ['value' => '<p>Body</p>']);

        // layoutC = [contents, bottom]: the top gadget is not rendered, and there is no LayoutA.
        $this->actingAs($member)->get('/')
            ->assertOk()
            ->assertSee('id="LayoutC"', false)
            ->assertSee('<p>Body</p>', false)
            ->assertDontSee('<p>TopNews</p>', false);
    }
}
