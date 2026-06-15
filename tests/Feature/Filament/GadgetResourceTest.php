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
}
