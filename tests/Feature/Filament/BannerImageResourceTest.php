<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\BannerImages\Pages\CreateBannerImage;
use App\Filament\Resources\BannerImages\Pages\ListBannerImages;
use App\Models\AdminUser;
use App\Models\BannerImage;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The upload/replace/delete logic lives in App\Features\Banner\Actions and is covered directly by
 * BannerImageActionsTest (storeFiles(false) temporary uploads can't be driven through Livewire's test
 * harness); this covers the page's form wiring and validation.
 */
class BannerImageResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->create(), 'admin');
    }

    public function test_the_create_page_loads(): void
    {
        Livewire::test(CreateBannerImage::class)->assertOk();
    }

    public function test_creating_requires_an_image(): void
    {
        Livewire::test(CreateBannerImage::class)
            ->fillForm(['url' => 'https://ad.example.test', 'name' => 'Promo'])
            ->call('create')
            ->assertHasFormErrors(['image']);

        $this->assertSame(0, BannerImage::count());
    }

    public function test_the_list_page_loads(): void
    {
        Livewire::test(ListBannerImages::class)->assertOk();
    }
}
