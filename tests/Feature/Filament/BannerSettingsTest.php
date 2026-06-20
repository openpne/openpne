<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\BannerSettings;
use App\Models\AdminUser;
use App\Models\Banner;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The per-placement banner mode page: each placement is either image mode (default) or operator HTML.
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

    public function test_saving_sets_the_mode_and_html_per_placement(): void
    {
        Livewire::test(BannerSettings::class)
            ->fillForm([
                'top_after_use_html' => true,
                'top_after_html' => '<div class="promo">Hi</div>',
                'top_before_use_html' => false,
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

    public function test_it_preserves_existing_placement_images(): void
    {
        // Saving the mode must update the existing row, not recreate it (which would drop its images).
        $banner = Banner::create(['name' => 'top_after']);
        $originalId = $banner->getKey();

        Livewire::test(BannerSettings::class)
            ->fillForm(['top_after_use_html' => true, 'top_after_html' => '<p>x</p>'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($originalId, Banner::where('name', 'top_after')->value('id'));
    }
}
