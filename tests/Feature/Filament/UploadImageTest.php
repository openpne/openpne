<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\UploadImage;
use App\Models\AdminUser;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The admin upload-image page renders. The FileUpload field needs a real Livewire temp upload the test
 * harness can't drive, so the byte+visibility path is covered at the FileUploader seam
 * (Tests\Feature\File\AdminImageUploadTest) and the guest delivery at PublicFileDeliveryTest.
 */
class UploadImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->create(), 'admin');
    }

    public function test_page_renders(): void
    {
        Livewire::test(UploadImage::class)->assertSuccessful();
    }
}
