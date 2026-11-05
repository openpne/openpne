<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Files\FileUploader;
use App\Files\GeneratedIcon;
use App\Models\File;
use App\Support\FaviconFallback;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The generated icon's bytes are hand-built (no GD on the way out), so the cases decode them with
 * GD on the way in: a decoder that reads them is the proof the shells can.
 */
class SiteIconTest extends TestCase
{
    use RefreshDatabase;

    private const OP_LOGO_ASSETS = ['favicon.ico', 'favicon.svg', 'favicon-16x16.png', 'favicon-32x32.png', 'apple-touch-icon.png', 'icon-192x192.png', 'icon-512x512.png'];

    public function test_the_svg_carries_the_initial_in_the_brand_colors(): void
    {
        $this->setSnsSetting(SnsSettingKey::SnsName, 'My SNS');
        $this->setSnsSetting(SnsSettingKey::BrandColor, '#0088aa');

        $svg = $this->get('/favicon.svg')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->getContent();

        $this->assertStringContainsString('fill="#0088aa"', $svg);
        $this->assertStringContainsString('>MY</text>', $svg);
        // #0088aa reads better under black (App\Support\BrandColor).
        $this->assertStringContainsString('fill="#000000"', $svg);
    }

    public function test_a_japanese_name_gives_one_character_and_markup_in_the_name_is_escaped(): void
    {
        $this->setSnsSetting(SnsSettingKey::SnsName, 'てじまや');
        $this->assertStringContainsString('>て</text>', $this->get('/favicon.svg')->assertOk()->getContent());

        $this->setSnsSetting(SnsSettingKey::SnsName, '<b>');
        $this->assertStringContainsString('>&lt;B</text>', $this->get('/favicon.svg')->assertOk()->getContent());
    }

    public function test_the_ico_wraps_a_32px_png_of_the_brand_color(): void
    {
        $this->setSnsSetting(SnsSettingKey::BrandColor, '#0088aa');

        $ico = $this->get('/favicon.ico')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/x-icon')
            ->getContent();

        $this->assertSame("\0\0\1\0\1\0", substr($ico, 0, 6));
        $this->assertSame([32, 32, 0x00, 0x88, 0xAA], $this->decode(substr($ico, 22)));
    }

    public function test_the_tile_is_a_square_of_the_brand_color_at_every_shell_size(): void
    {
        $this->setSnsSetting(SnsSettingKey::BrandColor, '#0088aa');

        foreach ([180, 192, 512] as $size) {
            $png = $this->get("/site-icon/{$size}.png")
                ->assertOk()
                ->assertHeader('Content-Type', 'image/png')
                ->getContent();

            $this->assertSame([$size, $size, 0x00, 0x88, 0xAA], $this->decode($png), (string) $size);
        }

        $this->get('/site-icon/64.png')->assertNotFound();
    }

    public function test_the_version_follows_the_color_and_the_name(): void
    {
        $before = app(GeneratedIcon::class)->version();

        $this->setSnsSetting(SnsSettingKey::BrandColor, '#0088aa');
        $afterColor = app(GeneratedIcon::class)->version();
        $this->assertNotSame($before, $afterColor);

        $this->setSnsSetting(SnsSettingKey::SnsName, 'Renamed');
        $this->assertNotSame($afterColor, app(GeneratedIcon::class)->version());
    }

    public function test_an_uploaded_favicon_answers_favicon_ico_with_a_redirect_and_the_svg_with_nothing(): void
    {
        $file = app(FileUploader::class)->store(
            UploadedFile::fake()->image('icon.png', 32, 32),
            explicitVisibility: File::VISIBILITY_PUBLIC,
        );
        $this->setSnsSetting(SnsSettingKey::BrandFaviconFile, $file->name);

        $this->get('/favicon.ico')->assertRedirect(route('file.public', ['file' => $file->name]));
        $this->get('/favicon.svg')->assertNotFound();
        $this->get('/site-icon/192.png')->assertOk();
    }

    public function test_a_site_set_to_no_icon_answers_favicon_ico_with_a_bare_cached_404(): void
    {
        $this->setSnsSetting(SnsSettingKey::BrandFaviconFallback, FaviconFallback::None);

        $response = $this->get('/favicon.ico')
            ->assertNotFound()
            ->assertHeader('Cache-Control', 'max-age=86400, public');

        $this->assertSame('', $response->getContent());
        $this->get('/favicon.svg')->assertNotFound();
        $this->get('/site-icon/192.png')->assertOk();
    }

    public function test_no_icon_asset_is_shipped_in_public(): void
    {
        foreach (self::OP_LOGO_ASSETS as $asset) {
            $this->assertFileDoesNotExist(public_path($asset), $asset);
        }
    }

    /** @return array{int, int, int, int, int} width, height, and the RGB of the first pixel */
    private function decode(string $png): array
    {
        $image = imagecreatefromstring($png);
        $this->assertNotFalse($image);
        $rgb = imagecolorat($image, 0, 0);

        return [imagesx($image), imagesy($image), ($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
    }
}
