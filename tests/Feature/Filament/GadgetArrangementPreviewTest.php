<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\Gadgets\Pages\ListGadgets;
use App\Filament\Resources\Gadgets\Widgets\GadgetArrangementPreview;
use App\Models\AdminUser;
use App\Models\Gadget;
use App\Services\GadgetService;
use App\Support\SnsSettingKey;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GadgetArrangementPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->create(), 'admin');
        app()->setLocale('en');
    }

    private function makeGadget(string $context, string $zone, string $name, ?int $sortOrder = 0): void
    {
        Gadget::create(['context' => $context, 'zone' => $zone, 'name' => $name, 'sort_order' => $sortOrder]);
        app(GadgetService::class)->clearCache();
    }

    public function test_a_placed_gadget_renders_as_a_chip_in_its_zone(): void
    {
        // freeArea on home is members-only; it must still appear (preview uses a member viewer).
        $this->makeGadget('home', 'contents', 'freeArea');

        Livewire::test(GadgetArrangementPreview::class)
            ->assertSee('Free Area')
            ->assertSee('Contents');
    }

    public function test_all_four_contexts_render_with_a_layout_letter(): void
    {
        Livewire::test(GadgetArrangementPreview::class)
            ->assertSee('Home page')
            ->assertSee('Profile page')
            ->assertSee('Login page')
            ->assertSee('Side banner')
            ->assertSee('Layout A');
    }

    public function test_a_kind_invalid_for_the_context_is_not_an_active_chip(): void
    {
        // loginForm is login-only; placed on home the renderer drops it, so it is counted, not shown.
        $this->makeGadget('home', 'contents', 'loginForm');

        Livewire::test(GadgetArrangementPreview::class)
            ->assertDontSee('Login Form')
            ->assertSee('are not shown in this layout');
    }

    public function test_a_gadget_in_a_zone_the_active_layout_hides_is_not_shown(): void
    {
        $this->setSnsSetting(SnsSettingKey::GadgetHomeLayout, 'layoutC'); // no `top` zone
        $this->makeGadget('home', 'top', 'informationBox');

        Livewire::test(GadgetArrangementPreview::class)
            ->assertSee('Layout C')
            ->assertDontSee('Information Box')
            ->assertSee('are not shown in this layout');
    }

    public function test_an_unknown_stored_layout_falls_back_like_the_renderer(): void
    {
        $this->setSnsSetting(SnsSettingKey::GadgetHomeLayout, 'bogus'); // -> layoutA set + letter
        $this->makeGadget('home', 'top', 'informationBox');

        Livewire::test(GadgetArrangementPreview::class)
            ->assertSee('Layout A')
            ->assertSee('Information Box');
    }

    public function test_chip_order_matches_the_renderers_null_aware_sort(): void
    {
        $this->makeGadget('home', 'contents', 'informationBox', 5);
        $this->makeGadget('home', 'contents', 'freeArea', null); // null sorts last

        $html = Livewire::test(GadgetArrangementPreview::class)->html();

        $this->assertLessThan(
            strpos($html, 'Free Area'),
            strpos($html, 'Information Box'),
            'Information Box (sort 5) should precede Free Area (null sort_order).',
        );
    }

    public function test_an_unsupported_kind_is_counted_not_shown(): void
    {
        $this->makeGadget('home', 'contents', 'notARealGadget');

        Livewire::test(GadgetArrangementPreview::class)
            ->assertDontSee('notARealGadget')
            ->assertSee('are not shown in this layout');
    }

    public function test_the_preview_widget_is_mounted_on_the_list_page(): void
    {
        Livewire::test(ListGadgets::class)
            ->assertSeeLivewire(GadgetArrangementPreview::class);
    }

    public function test_the_widget_refreshes_on_the_reorder_event(): void
    {
        Livewire::test(GadgetArrangementPreview::class)
            ->dispatch('gadgets-arranged')
            ->assertOk();
    }
}
