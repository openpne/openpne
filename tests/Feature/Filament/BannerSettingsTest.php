<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\BannerSettings;
use App\Models\AdminUser;
use App\Models\Banner;
use App\Models\BannerImage;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The per-placement banner page: each placement is either image mode (pick from the shared pool) or
 * operator HTML.
 */
class BannerSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->create(), 'admin');
    }

    public function test_the_page_loads_with_a_section_per_placement(): void
    {
        app()->setLocale('en');
        BannerImage::factory()->create(['name' => 'Promo']);

        Livewire::test(BannerSettings::class)
            ->assertOk()
            ->assertSee('Top banner (before login)')
            ->assertSee('Top banner (after login)')
            // The image pool is offered for selection and the upload screen is linked.
            ->assertSee('Promo')
            ->assertSee('Add or manage banner images');
    }

    public function test_saving_sets_the_mode_and_html_per_placement(): void
    {
        Livewire::test(BannerSettings::class)
            ->fillForm([
                'top_after_mode' => 'html',
                'top_after_html' => '<div class="promo">Hi</div>',
                'top_before_mode' => 'images',
                'top_before_html' => '',
            ])
            ->call('save')
            ->assertHasNoErrors();

        $after = Banner::where('name', 'top_after')->first();
        $this->assertTrue($after->is_use_html);
        $this->assertSame('<div class="promo">Hi</div>', $after->html);

        $before = Banner::where('name', 'top_before')->first();
        $this->assertFalse($before->is_use_html);
        $this->assertNull($before->html);
    }

    public function test_selecting_images_syncs_them_to_the_placement(): void
    {
        $a = BannerImage::factory()->create();
        $b = BannerImage::factory()->create();

        Livewire::test(BannerSettings::class)
            ->fillForm([
                'top_before_mode' => 'images',
                'top_before_images' => [$a->getKey(), $b->getKey()],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $banner = Banner::where('name', 'top_before')->first();
        $this->assertEqualsCanonicalizing([$a->getKey(), $b->getKey()], $banner->images->pluck('id')->all());
    }

    public function test_switching_to_html_keeps_the_image_selection(): void
    {
        // Saving in HTML mode must not drop the placement's images (and must update the row, not
        // recreate it), so a round-trip back to image mode shows them again.
        $banner = Banner::create(['name' => 'top_after']);
        $image = BannerImage::factory()->create();
        $banner->images()->attach($image);

        Livewire::test(BannerSettings::class)
            ->fillForm(['top_after_mode' => 'html', 'top_after_html' => '<p>x</p>'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($banner->getKey(), Banner::where('name', 'top_after')->value('id'));
        $this->assertTrue($banner->fresh()->images->contains($image));
    }
}
