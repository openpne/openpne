<?php

namespace Tests\Feature\Classic;

use App\Models\Gadget;
use App\Models\GadgetConfig;
use App\Models\Member;
use App\Services\GadgetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassicSideBannerGadgetTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, string> $config */
    private function makeGadget(string $name, array $config = []): Gadget
    {
        $gadget = Gadget::create(['context' => 'sidebanner', 'zone' => 'contents', 'name' => $name, 'sort_order' => 0]);
        foreach ($config as $key => $value) {
            GadgetConfig::create(['gadget_id' => $gadget->id, 'name' => $key, 'value' => $value]);
        }
        app(GadgetService::class)->clearCache();

        return $gadget;
    }

    public function test_side_banner_renders_globally_for_a_member(): void
    {
        $member = Member::factory()->create();
        $this->makeGadget('languageSelecterBox');

        $this->actingAs($member)->get('/')
            ->assertOk()
            ->assertSee('id="sideBanner"', false)
            ->assertSee(route('locale.switch'), false) // the language-selecter form
            ->assertSee('English');
    }

    public function test_side_banner_is_public_for_a_guest(): void
    {
        // The login page is guest-reachable; the side banner shows there too (global, all PC pages).
        $this->makeGadget('informationBox', ['value' => '<p>Banner notice</p>']);

        $this->get('/login')
            ->assertOk()
            ->assertSee('id="sideBanner"', false)
            ->assertSee('<p>Banner notice</p>', false);
    }

    public function test_no_side_banner_div_when_empty(): void
    {
        $member = Member::factory()->create();

        // No side-banner gadgets: the reserved column is not rendered as an empty float.
        $this->actingAs($member)->get('/')
            ->assertOk()
            ->assertDontSee('id="sideBanner"', false);
    }
}
