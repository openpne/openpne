<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\BannerImages\Pages\CreateBannerImage;
use App\Filament\Resources\BannerImages\Pages\EditBannerImage;
use App\Filament\Resources\BannerImages\Pages\ListBannerImages;
use App\Models\AdminUser;
use App\Models\BannerImage;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * storeFiles(false) temporary uploads cannot be driven through Livewire's test harness, so the
 * upload/replace/delete behaviour is asserted on the actions directly; this covers the form wiring
 * and validation.
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

    public function test_the_image_field_follows_the_configured_cap(): void
    {
        config()->set('openpne.images.max_upload_kilobytes', 123);

        Livewire::test(CreateBannerImage::class)
            ->assertFormFieldExists('image', checkFieldUsing: fn (FileUpload $field): bool => $field->getMaxSize() === 123);
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

    public function test_the_list_thumbnail_opens_the_lightbox(): void
    {
        // A row present exercises the thumbnail column, which is wired to the shared lightbox on click.
        $image = BannerImage::factory()->create(['name' => 'Promo']);

        Livewire::test(ListBannerImages::class)
            ->assertOk()
            ->assertSee($image->file->name)
            ->assertSee('open-image-lightbox')
            // Data rides on data-* attributes (not a JSON literal that attribute escaping would mangle).
            ->assertSee('data-lb-src');
    }

    public function test_the_edit_page_preview_opens_the_lightbox(): void
    {
        // The current-image preview reads the record and is wired to the shared lightbox on click.
        $image = BannerImage::factory()->create(['name' => 'Promo']);

        Livewire::test(EditBannerImage::class, ['record' => $image->getRouteKey()])
            ->assertOk()
            ->assertSee($image->file->name)
            ->assertSee('open-image-lightbox')
            ->assertSee('data-lb-src');
    }
}
