<?php

namespace Tests\Feature\Classic;

use App\Features\Banner\Actions\StoreBannerImage;
use App\Models\Banner;
use App\Models\BannerImage;
use App\Models\Gadget;
use App\Models\GadgetConfig;
use App\Models\Member;
use App\Services\GadgetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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

    private function addImage(Banner $banner, ?string $url = null, ?string $label = null): BannerImage
    {
        return app(StoreBannerImage::class)(
            UploadedFile::fake()->image('banner.png', 40, 40),
            $url,
            $label,
            [$banner->getKey()],
        );
    }

    /** The rendered HTML of the #sideBanner column (between its open tag and the trailing marker). */
    private function sideBannerColumn(string $html): string
    {
        $open = strpos($html, '<div id="sideBanner">');
        $this->assertNotFalse($open, 'expected the #sideBanner column to be present');
        $close = strpos($html, '<!-- sideBanner -->', $open);

        return substr($html, $open, (int) $close - $open);
    }

    public function test_side_banner_renders_globally_for_a_member(): void
    {
        $member = Member::factory()->create();
        $this->makeGadget('languageSelecterBox');

        $this->actingAs($member)->get('/')
            ->assertOk()
            ->assertSee('id="sideBanner"', false)
            // OpenPNE 3's bare form: label + colon + self-submitting select. No box, no button.
            ->assertSee('data-language-switch', false)
            ->assertSee('<label for="language_culture">', false)
            ->assertDontSee('class="dparts box"', false)
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

    public function test_side_banner_gadget_shows_the_after_login_placement_to_a_member(): void
    {
        $member = Member::factory()->create();
        $this->makeGadget('sideBanner');
        Banner::create(['name' => 'side_before', 'is_use_html' => true, 'html' => '<div class="side-before">Guest side</div>']);
        Banner::create(['name' => 'side_after', 'is_use_html' => true, 'html' => '<div class="side-promo">Member side</div>']);

        $this->actingAs($member)->get('/')
            ->assertOk()
            ->assertSee('<div class="side-promo">Member side</div>', false)
            ->assertDontSee('Guest side');
    }

    public function test_side_banner_gadget_shows_the_before_login_placement_to_a_guest(): void
    {
        $this->makeGadget('sideBanner');
        Banner::create(['name' => 'side_before', 'is_use_html' => true, 'html' => '<div class="side-promo">Guest side</div>']);
        Banner::create(['name' => 'side_after', 'is_use_html' => true, 'html' => '<div class="side-after">Member side</div>']);

        $this->get('/login')
            ->assertOk()
            ->assertSee('<div class="side-promo">Guest side</div>', false)
            ->assertDontSee('Member side');
    }

    public function test_side_banner_gadget_shows_a_random_image_with_its_link(): void
    {
        $member = Member::factory()->create();
        $this->makeGadget('sideBanner');
        $image = $this->addImage(Banner::create(['name' => 'side_after']), 'https://ad.example.test', 'Promo');

        $this->actingAs($member)->get('/')
            ->assertOk()
            ->assertSee(route('banner.image', $image->file->name), false)
            ->assertSee('alt="Promo"', false)
            ->assertSee('<a href="https://ad.example.test" target="_blank" rel="noopener">', false);
    }

    public function test_side_banner_gadget_is_emitted_bare_without_a_wrapper(): void
    {
        $member = Member::factory()->create();
        $this->makeGadget('sideBanner');
        Banner::create(['name' => 'side_after', 'is_use_html' => true, 'html' => '<div class="side-promo">Member side</div>']);

        $column = $this->sideBannerColumn(
            $this->actingAs($member)->get('/')->assertOk()->getContent(),
        );

        // OpenPNE 3's op_banner emits the banner with no wrapper — no parts box, no per-gadget id.
        $this->assertStringContainsString('<div class="side-promo">Member side</div>', $column);
        $this->assertStringNotContainsString('parts', $column);
        $this->assertStringNotContainsString('sideBanner_', $column);
    }

    public function test_side_banner_column_stays_when_the_placement_is_unconfigured(): void
    {
        $member = Member::factory()->create();
        // A sideBanner gadget row with no matching Banner placement: the column persists (existing
        // contract), but the gadget contributes nothing.
        $this->makeGadget('sideBanner');

        $column = $this->sideBannerColumn(
            $this->actingAs($member)->get('/')
                ->assertOk()
                ->assertSee('id="sideBanner"', false)
                ->getContent(),
        );

        $this->assertStringNotContainsString('<img', $column);
        $this->assertStringNotContainsString('banner.image', $column);
    }
}
