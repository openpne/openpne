<?php

namespace Tests\Feature\Classic;

use App\Features\Banner\Actions\StoreBannerImage;
use App\Models\Banner;
use App\Models\BannerImage;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The Classic #topBanner (OpenPNE 3 op_banner): top_after for a logged-in member, top_before for a
 * guest; operator HTML or a (single, random) image with an optional link.
 */
class ClassicTopBannerTest extends TestCase
{
    use RefreshDatabase;

    private function addImage(Banner $banner, ?string $url = null, ?string $label = null): BannerImage
    {
        return app(StoreBannerImage::class)(
            UploadedFile::fake()->image('banner.png', 40, 40),
            $url,
            $label,
            [$banner->getKey()],
        );
    }

    public function test_a_member_sees_the_after_login_image_banner_with_its_link(): void
    {
        $member = Member::factory()->create();
        $image = $this->addImage(Banner::create(['name' => 'top_after']), 'https://ad.example.test', 'Promo');

        $this->actingAs($member)->get('/')
            ->assertOk()
            ->assertSee('id="topBanner"', false)
            ->assertSee(route('banner.image', $image->file->name), false)
            ->assertSee('alt="Promo"', false)
            ->assertSee('<a href="https://ad.example.test" target="_blank" rel="noopener">', false);
    }

    public function test_a_guest_sees_the_before_login_banner(): void
    {
        // The login page is guest-reachable and uses the Classic shell.
        $image = $this->addImage(Banner::create(['name' => 'top_before']));

        $this->get('/login')
            ->assertOk()
            ->assertSee('id="topBanner"', false)
            ->assertSee(route('banner.image', $image->file->name), false);
    }

    public function test_html_mode_emits_the_operator_html(): void
    {
        $member = Member::factory()->create();
        Banner::create(['name' => 'top_after', 'is_use_html' => true, 'html' => '<div class="promo">Hello</div>']);

        $this->actingAs($member)->get('/')
            ->assertOk()
            ->assertSee('<div class="promo">Hello</div>', false);
    }

    public function test_the_banner_div_is_empty_when_nothing_is_configured(): void
    {
        $member = Member::factory()->create();
        Banner::create(['name' => 'top_after']); // image mode, no images

        $this->actingAs($member)->get('/')
            ->assertOk()
            ->assertSee('<div id="topBanner"></div>', false);
    }

    public function test_a_guest_can_fetch_a_banner_image(): void
    {
        // FilePolicy treats banner images as public, so the before-login banner loads without auth.
        $image = $this->addImage(Banner::create(['name' => 'top_before']));

        $this->get(route('banner.image', $image->file->name))->assertOk();
    }
}
