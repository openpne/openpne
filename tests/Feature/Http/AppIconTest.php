<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Files\FileUploader;
use App\Models\File;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The home-screen icon derived from the branding favicon. A tab favicon and an app icon have very
 * different display sizes, so what matters here is what happens when the uploaded image cannot
 * carry the larger one — and that an alpha channel never reaches iOS, which paints it black.
 */
class AppIconTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('image_cache');
    }

    private function setFavicon(int $side, bool $transparent = false): File
    {
        $upload = $transparent
            ? UploadedFile::fake()->createWithContent('icon.png', self::transparentPng($side))
            : UploadedFile::fake()->image('icon.png', $side, $side);

        $file = app(FileUploader::class)->store($upload, explicitVisibility: File::VISIBILITY_PUBLIC);
        $this->setSnsSetting(SnsSettingKey::BrandFaviconFile, $file->name);

        return $file;
    }

    /** A fully transparent square, which is what a logo mark with a cut-out background reduces to. */
    private static function transparentPng(int $side): string
    {
        $image = imagecreatetruecolor($side, $side);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    public function test_an_unbranded_install_has_no_icon_to_derive(): void
    {
        $this->get('/app-icon/512.png')->assertNotFound();
    }

    public function test_a_size_no_shell_asks_for_is_not_generated(): void
    {
        $this->setFavicon(512);

        $this->get('/app-icon/1024.png')->assertNotFound();
    }

    public function test_a_large_favicon_becomes_the_icon_at_the_requested_size(): void
    {
        $this->setFavicon(512);

        $response = $this->get('/app-icon/192.png')->assertOk()->assertHeader('Content-Type', 'image/png');

        $this->assertSame([192, 192], $this->dimensionsOf($response->getContent()));
    }

    public function test_a_tab_sized_favicon_keeps_the_shipped_icon_instead_of_upscaling(): void
    {
        $this->setFavicon(32);

        $this->assertSame(
            file_get_contents(public_path('icon-512x512.png')),
            $this->get('/app-icon/512.png')->assertOk()->getContent(),
        );
    }

    public function test_a_favicon_serves_only_the_sizes_it_can_fill(): void
    {
        $this->setFavicon(192);

        $this->assertSame([192, 192], $this->dimensionsOf($this->get('/app-icon/192.png')->getContent()));
        $this->assertSame(
            file_get_contents(public_path('icon-512x512.png')),
            $this->get('/app-icon/512.png')->getContent(),
        );
    }

    public function test_transparency_is_flattened_so_ios_does_not_paint_it_black(): void
    {
        $this->setFavicon(512, transparent: true);

        $image = imagecreatefromstring($this->get('/app-icon/512.png')->getContent());
        $this->assertNotFalse($image);

        $this->assertSame(
            ['red' => 255, 'green' => 255, 'blue' => 255, 'alpha' => 0],
            imagecolorsforindex($image, imagecolorat($image, 0, 0)),
        );
    }

    public function test_a_replaced_favicon_replaces_the_icon(): void
    {
        $this->setFavicon(512);
        $this->get('/app-icon/192.png')->assertOk();

        // The derived bytes are cached under the source token; a new upload must not read them back.
        $this->setFavicon(32);

        $this->assertSame(
            file_get_contents(public_path('icon-192x192.png')),
            $this->get('/app-icon/192.png')->getContent(),
        );
    }

    /** @return array{int, int} */
    private function dimensionsOf(string $bytes): array
    {
        $size = getimagesizefromstring($bytes);
        $this->assertNotFalse($size);

        return [$size[0], $size[1]];
    }
}
