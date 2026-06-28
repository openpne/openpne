<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\Gadgets\GadgetResource;
use App\Filament\Resources\Gadgets\Pages\CreateGadget;
use App\Filament\Resources\Gadgets\Pages\EditGadget;
use App\Filament\Resources\Gadgets\Pages\ListGadgets;
use App\Models\AdminUser;
use App\Models\Gadget;
use App\Models\GadgetConfig;
use App\Models\Member;
use App\Services\GadgetService;
use App\Support\SnsSettingKey;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GadgetResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->create(), 'admin');
    }

    public function test_list_tab_scopes_to_context(): void
    {
        $home = Gadget::create(['context' => 'home', 'zone' => 'contents', 'name' => 'freeArea', 'sort_order' => 0]);
        $profile = Gadget::create(['context' => 'profile', 'zone' => 'sideMenu', 'name' => 'profileListBox', 'sort_order' => 0]);

        Livewire::test(ListGadgets::class)
            ->set('activeTab', 'home')
            ->assertCanSeeTableRecords([$home])
            ->assertCanNotSeeTableRecords([$profile]);
    }

    public function test_kind_options_are_scoped_to_the_context(): void
    {
        $this->assertArrayHasKey('freeArea', GadgetResource::kindOptions('home'));
        $this->assertArrayNotHasKey('loginForm', GadgetResource::kindOptions('home')); // login-only
        $this->assertArrayHasKey('loginForm', GadgetResource::kindOptions('login'));
    }

    public function test_creates_a_gadget_with_config_and_clears_cache(): void
    {
        $viewer = Member::factory()->create();

        Livewire::test(CreateGadget::class)
            ->fillForm([
                'context' => 'home',
                'name' => 'freeArea',
                'zone' => 'contents',
                'sort_order' => 10,
                'config_title' => 'Notice',
                'config_value' => '<b>Hello</b>',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $gadget = Gadget::query()->where('name', 'freeArea')->first();
        $this->assertNotNull($gadget);
        $this->assertDatabaseHas('gadget_configs', ['gadget_id' => $gadget->id, 'name' => 'title', 'value' => 'Notice']);
        $this->assertDatabaseHas('gadget_configs', ['gadget_id' => $gadget->id, 'name' => 'value', 'value' => '<b>Hello</b>']);

        // cache was cleared, so the renderer sees the new gadget and its config immediately
        $item = app(GadgetService::class)->zones('home', null, $viewer)['contents'][0];
        $this->assertSame('freeArea', $item['name']);
        $this->assertSame('Notice', $item['config']['title']);
    }

    public function test_edit_updates_config_and_clears_cache(): void
    {
        $viewer = Member::factory()->create();
        $gadget = Gadget::create(['context' => 'home', 'zone' => 'contents', 'name' => 'freeArea', 'sort_order' => 0]);
        GadgetConfig::create(['gadget_id' => $gadget->id, 'name' => 'title', 'value' => 'Old']);

        Livewire::test(EditGadget::class, ['record' => $gadget->getKey()])
            ->fillForm(['config_title' => 'New'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('gadget_configs', ['gadget_id' => $gadget->id, 'name' => 'title', 'value' => 'New']);
        $item = app(GadgetService::class)->zones('home', null, $viewer)['contents'][0];
        $this->assertSame('New', $item['config']['title']);
    }

    public function test_rejects_a_kind_not_offered_in_the_context(): void
    {
        // loginForm is login-only; placing it on the home page must fail validation, not save.
        Livewire::test(CreateGadget::class)
            ->fillForm([
                'context' => 'home',
                'name' => 'loginForm',
                'zone' => 'contents',
                'sort_order' => 10,
            ])
            ->call('create')
            ->assertHasFormErrors(['name']);
    }

    public function test_service_skips_a_row_whose_kind_is_not_valid_for_its_context(): void
    {
        $viewer = Member::factory()->create();
        // A row planted out of context (e.g. a hand-edited DB / unexpected upgrade) must not render.
        Gadget::create(['context' => 'home', 'zone' => 'contents', 'name' => 'loginForm', 'sort_order' => 0]);

        $this->assertSame([], app(GadgetService::class)->zones('home', null, $viewer)['contents']);
    }

    public function test_table_flags_an_unsupported_kind(): void
    {
        // A gadget whose kind is not registered (an OpenPNE 3 kind not yet ported) is flagged.
        $gadget = Gadget::create(['context' => 'home', 'zone' => 'contents', 'name' => 'rssBox', 'sort_order' => 0]);

        Livewire::test(ListGadgets::class)
            ->set('activeTab', 'home')
            ->assertTableColumnStateSet('status', __('Unsupported'), $gadget);
    }

    public function test_placements_groups_by_zone_with_nulls_last(): void
    {
        Gadget::create(['context' => 'home', 'zone' => 'contents', 'name' => 'freeArea', 'sort_order' => null]);
        Gadget::create(['context' => 'home', 'zone' => 'contents', 'name' => 'informationBox', 'sort_order' => 5]);
        Gadget::create(['context' => 'home', 'zone' => 'contents', 'name' => 'rssBox', 'sort_order' => 0]); // unregistered

        $contents = GadgetResource::placements('home')['contents'];

        // numeric sort_order first (0, 5), null last; unregistered kind falls back to the raw name.
        $this->assertSame(['rssBox', 'Information Box', 'Free Area'], $contents);
    }

    public function test_zone_picker_shows_existing_gadgets_and_the_pages_zones(): void
    {
        app()->setLocale('en');
        Gadget::create(['context' => 'home', 'zone' => 'contents', 'name' => 'informationBox', 'sort_order' => 0]);

        Livewire::test(CreateGadget::class)
            ->fillForm(['context' => 'home'])
            ->assertSee(__('Click an area of the page to place the gadget.')) // tells the operator the areas are clickable
            ->assertSee('Information Box')   // existing gadget chip
            ->assertSee(__('Top'))           // the home page's zones are drawn
            ->assertSee(__('Main area'));
    }

    public function test_zone_picker_marks_zones_the_current_layout_hides(): void
    {
        app()->setLocale('en');
        $this->setSnsSetting(SnsSettingKey::GadgetHomeLayout, 'layoutC'); // contents/bottom only

        Livewire::test(CreateGadget::class)
            ->fillForm(['context' => 'home'])
            ->assertSee(__('Not shown in the current layout.')); // top / sideMenu are dimmed
    }

    public function test_rejects_a_zone_not_valid_for_the_context(): void
    {
        Livewire::test(CreateGadget::class)
            ->fillForm(['context' => 'home', 'name' => 'freeArea', 'zone' => 'bogus', 'sort_order' => 10])
            ->call('create')
            ->assertHasFormErrors(['zone']);
    }

    public function test_edit_shows_only_the_fixed_kind_not_the_whole_list(): void
    {
        app()->setLocale('en');
        $gadget = Gadget::create(['context' => 'home', 'zone' => 'contents', 'name' => 'freeArea', 'sort_order' => 0]);

        // The kind can't change on edit, so the radio shows only the chosen kind — not every home kind.
        Livewire::test(EditGadget::class, ['record' => $gadget->getKey()])
            ->assertSee('Free Area')
            ->assertDontSee('Information Box');
    }

    public function test_edit_can_move_a_gadget_to_another_zone(): void
    {
        $gadget = Gadget::create(['context' => 'home', 'zone' => 'contents', 'name' => 'freeArea', 'sort_order' => 0]);

        Livewire::test(EditGadget::class, ['record' => $gadget->getKey()])
            ->fillForm(['zone' => 'top'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('gadgets', ['id' => $gadget->id, 'zone' => 'top']);
    }

    public function test_gadget_kinds_are_listed_with_descriptions(): void
    {
        app()->setLocale('en');

        // The radio list shows every kind for the context with its one-line description — visible without
        // selecting one first (unlike the old dropdown).
        Livewire::test(CreateGadget::class)
            ->fillForm(['context' => 'home'])
            ->assertSee(__('A free area for a custom title and HTML/text.'))
            ->assertSee(__('A member search box.'));
    }

    public function test_list_table_shows_the_kind_description(): void
    {
        app()->setLocale('en');
        Gadget::create(['context' => 'home', 'zone' => 'contents', 'name' => 'freeArea', 'sort_order' => 0]);

        Livewire::test(ListGadgets::class)
            ->set('activeTab', 'home')
            ->assertSee(__('A free area for a custom title and HTML/text.'));
    }

    public function test_create_inherits_the_context_from_the_query(): void
    {
        app()->setLocale('en');
        Gadget::create(['context' => 'home', 'zone' => 'contents', 'name' => 'informationBox', 'sort_order' => 0]);

        // The list's Create button links to ?context=<active tab>; the form pre-selects that page so its
        // diagram renders immediately, instead of the "select a placement first" prompt.
        $this->get(GadgetResource::getUrl('create', ['context' => 'home']))
            ->assertOk()
            ->assertSee('Information Box')
            ->assertDontSee(__('Select a placement first.'));
    }

    public function test_create_without_a_context_prompts_for_one(): void
    {
        app()->setLocale('en');

        $this->get(GadgetResource::getUrl('create'))
            ->assertOk()
            ->assertSee(__('Select a placement first.'));
    }
}
