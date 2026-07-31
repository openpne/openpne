<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Gadgets\GadgetKindRegistry;
use App\Models\Gadget;
use App\Models\GadgetConfig;
use App\Models\Member;
use App\Services\GadgetService;
use App\Services\SnsSettingService;
use App\Support\Feature;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GadgetServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeGadget(string $context, string $zone, string $name, int $sort = 0, array $config = []): Gadget
    {
        $gadget = Gadget::create(['context' => $context, 'zone' => $zone, 'name' => $name, 'sort_order' => $sort]);
        foreach ($config as $key => $value) {
            GadgetConfig::create(['gadget_id' => $gadget->id, 'name' => $key, 'value' => $value]);
        }
        app(GadgetService::class)->clearCache();

        return $gadget;
    }

    private function names(array $items): array
    {
        return array_column($items, 'name');
    }

    public function test_groups_registered_gadgets_by_zone_in_sort_order(): void
    {
        $this->makeGadget('home', 'sideMenu', 'memberImageBox', 20);
        $this->makeGadget('home', 'sideMenu', 'friendListBox', 10);
        $this->makeGadget('home', 'contents', 'informationBox', 10);
        $viewer = Member::factory()->create();

        $zones = app(GadgetService::class)->zones('home', null, $viewer);

        // Sorted by sort_order within the zone.
        $this->assertSame(['friendListBox', 'memberImageBox'], $this->names($zones['sideMenu']));
        $this->assertSame(['informationBox'], $this->names($zones['contents']));
    }

    public function test_hides_an_unregistered_kind(): void
    {
        $this->makeGadget('home', 'contents', 'rssBox'); // not in the registry
        $viewer = Member::factory()->create();

        $zones = app(GadgetService::class)->zones('home', null, $viewer);

        $this->assertSame([], $zones['contents']);
    }

    public function test_hides_a_kind_whose_feature_unit_is_switched_off(): void
    {
        $this->makeGadget('home', 'sideMenu', 'friendListBox', 10);
        $this->makeGadget('home', 'sideMenu', 'memberImageBox', 20);
        $viewer = Member::factory()->create();

        $this->setSnsSetting(Feature::Friend->settingKey(), false);

        // The kind that names no unit stays; the friend grid goes.
        $zones = app(GadgetService::class)->zones('home', null, $viewer);
        $this->assertSame(['memberImageBox'], $this->names($zones['sideMenu']));
    }

    /** Every tagged kind, so a kind cannot be tagged and then silently keep rendering. */
    public function test_each_tagged_kind_reports_unavailable_while_its_unit_is_off(): void
    {
        foreach (GadgetKindRegistry::all() as $name => $kind) {
            $feature = $kind->feature();
            if ($feature === null) {
                $this->assertTrue($kind->isAvailable(), "{$name} names no unit, so it is always available");

                continue;
            }

            $this->assertTrue($kind->isAvailable(), "{$name} must be available while its unit is on");

            $this->setSnsSetting($feature->settingKey(), false);
            $this->assertFalse($kind->isAvailable(), "{$name} must disappear while {$feature->value} is off");
            $this->setSnsSetting($feature->settingKey(), true);
        }
    }

    public function test_a_board_kind_follows_the_communities_it_lives_inside(): void
    {
        $this->makeGadget('home', 'contents', 'recentCommunityTopicComment');
        $viewer = Member::factory()->create();

        // Its own row says enabled; the unit it lives inside overrules it.
        $this->setSnsSetting(Feature::CommunityTopic->settingKey(), true);
        $this->setSnsSetting(Feature::Community->settingKey(), false);

        $this->assertSame([], $this->names(app(GadgetService::class)->zones('home', null, $viewer)['contents']));
    }

    public function test_hides_a_members_only_kind_from_a_guest_but_shows_a_public_one(): void
    {
        // On the profile page profileListBox is public (viewable_privilege 4); friendListBox is members-only.
        $subject = Member::factory()->create();
        $this->makeGadget('profile', 'sideMenu', 'profileListBox', 10);
        $this->makeGadget('profile', 'sideMenu', 'friendListBox', 20);

        $guest = app(GadgetService::class)->zones('profile', $subject, null);
        $this->assertSame(['profileListBox'], $this->names($guest['sideMenu']));

        $member = app(GadgetService::class)->zones('profile', $subject, Member::factory()->create());
        $this->assertSame(['profileListBox', 'friendListBox'], $this->names($member['sideMenu']));
    }

    public function test_only_returns_zones_the_active_layout_exposes(): void
    {
        // layoutC = [contents, bottom], so a gadget in the `top` zone is not rendered.
        DB::table('sns_settings')->insert(['key' => SnsSettingKey::GadgetHomeLayout->value, 'value' => 'layoutC']);
        app(SnsSettingService::class)->clearCache();
        $this->makeGadget('home', 'top', 'informationBox');
        $this->makeGadget('home', 'contents', 'searchBox');
        $viewer = Member::factory()->create();

        $zones = app(GadgetService::class)->zones('home', null, $viewer);

        $this->assertArrayNotHasKey('top', $zones);
        $this->assertSame(['searchBox'], $this->names($zones['contents']));
    }

    public function test_types_config_values_with_defaults(): void
    {
        $this->makeGadget('home', 'contents', 'freeArea', 0, ['title' => 'Hello']); // value left unset
        $this->makeGadget('home', 'sideMenu', 'friendListBox'); // no config at all
        $viewer = Member::factory()->create();

        $zones = app(GadgetService::class)->zones('home', null, $viewer);

        $free = $zones['contents'][0];
        $this->assertSame('gadget.free-area', $free['component']);
        $this->assertSame('Hello', $free['config']['title']);
        $this->assertSame('', $free['config']['value']); // unset → default

        $grid = $zones['sideMenu'][0]['config'];
        $this->assertSame(3, $grid['row']); // typed int default
        $this->assertSame(3, $grid['col']);
        $this->assertSame('full', $grid['type']);
    }

    public function test_passes_through_kind_part_id_and_subject(): void
    {
        $subject = Member::factory()->create();
        $gadget = $this->makeGadget('home', 'contents', 'freeArea');

        $item = app(GadgetService::class)->zones('home', $subject, Member::factory()->create())['contents'][0];

        $this->assertSame('freeArea_'.$gadget->id, $item['partId']);
        $this->assertTrue($subject->is($item['subject'])); // the subject the caller passed reaches the item
    }

    public function test_conveys_the_render_context_to_each_item(): void
    {
        $subject = Member::factory()->create();
        $this->makeGadget('profile', 'contents', 'profileListBox');

        $item = app(GadgetService::class)->zones('profile', $subject, Member::factory()->create())['contents'][0];

        $this->assertSame('profile', $item['context']);
    }

    public function test_the_context_attribute_does_not_leak_into_rendered_gadgets(): void
    {
        // A class component (freeArea does not exist as one; timelineFriend is) and an anonymous one
        // (memberImageBox) both receive the new context attribute; neither may echo it into the DOM.
        $viewer = Member::factory()->create();
        $this->makeGadget('home', 'contents', 'timelineFriend');
        $this->makeGadget('home', 'sideMenu', 'memberImageBox');

        $this->actingAs($viewer)->get('/')
            ->assertOk()
            ->assertSee('homeFriendTimeline', false) // the gadgets did render
            ->assertSee('memberImageBox', false)
            ->assertDontSee('context="', false);     // the passthrough attribute is not emitted
    }
}
