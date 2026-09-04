<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Files\FileUploader;
use App\Models\File;
use App\Models\Member;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A tab favicon and an app icon have very different display sizes, so the cases are what happens when
 * the uploaded image cannot carry the larger one, and that an alpha channel never reaches iOS, which
 * paints it black.
 */
class AppIconTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('image_cache');
    }

    /** The icon URL the shells would render for the current favicon. */
    private function url(int $size): string
    {
        return app_icon_url($size);
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
        $this->get('/app-icon/whatever/512.png')->assertNotFound();
    }

    public function test_a_size_no_shell_asks_for_is_not_generated(): void
    {
        $file = $this->setFavicon(512);

        $this->get("/app-icon/{$file->name}/1024.png")->assertNotFound();
        // Past PHP_INT_MAX the typed size parameter would fail as a 500 rather than a 404.
        $this->get("/app-icon/{$file->name}/99999999999999999999.png")->assertNotFound();
    }

    public function test_a_setting_pointing_at_a_file_it_does_not_own_reads_nothing(): void
    {
        // An avatar specifically, because FilePolicy lets a guest view one, so only the
        // public-visibility check stands between a corrupted setting and the file.
        $file = app(FileUploader::class)->store(
            UploadedFile::fake()->image('avatar.png', 512, 512),
            'member',
            (int) Member::factory()->create()->getKey(),
        );
        $this->setSnsSetting(SnsSettingKey::BrandFaviconFile, $file->name);

        $this->get("/app-icon/{$file->name}/512.png")->assertNotFound();
    }

    public function test_a_token_that_is_no_longer_the_favicon_reads_nothing(): void
    {
        $replaced = $this->setFavicon(512);
        $this->setFavicon(512);

        $this->get("/app-icon/{$replaced->name}/512.png")->assertNotFound();
    }

    public function test_replacing_the_favicon_changes_the_url_the_shells_declare(): void
    {
        $this->setFavicon(512);
        $before = $this->url(512);

        $this->setFavicon(512);

        // An installed app updates its icon off a changed manifest entry, not off new bytes at an
        // unchanged URL.
        $this->assertNotSame($before, $this->url(512));
    }

    public function test_a_large_favicon_becomes_the_icon_at_the_requested_size(): void
    {
        $this->setFavicon(512);

        $response = $this->get($this->url(192))->assertOk()->assertHeader('Content-Type', 'image/png');

        $this->assertSame([192, 192], $this->dimensionsOf($response->getContent()));
    }

    public function test_a_tab_sized_favicon_keeps_the_shipped_icon_instead_of_upscaling(): void
    {
        $this->setFavicon(32);

        $this->assertSame(
            file_get_contents(public_path('icon-512x512.png')),
            $this->get($this->url(512))->assertOk()->getContent(),
        );
    }

    public function test_a_favicon_serves_only_the_sizes_it_can_fill(): void
    {
        $this->setFavicon(192);

        $this->assertSame([192, 192], $this->dimensionsOf($this->get($this->url(192))->getContent()));
        $this->assertSame(
            file_get_contents(public_path('icon-512x512.png')),
            $this->get($this->url(512))->getContent(),
        );
    }

    public function test_transparency_is_flattened_so_ios_does_not_paint_it_black(): void
    {
        $this->setFavicon(512, transparent: true);

        $image = imagecreatefromstring($this->get($this->url(512))->getContent());
        $this->assertNotFalse($image);

        $this->assertSame(
            ['red' => 255, 'green' => 255, 'blue' => 255, 'alpha' => 0],
            imagecolorsforindex($image, imagecolorat($image, 0, 0)),
        );
    }

    public function test_a_replaced_favicon_replaces_the_icon(): void
    {
        $this->setFavicon(512);
        $this->get($this->url(192))->assertOk();

        // The derived bytes are cached under the source token; a new upload must not read them back.
        $this->setFavicon(32);

        $this->assertSame(
            file_get_contents(public_path('icon-192x192.png')),
            $this->get($this->url(192))->getContent(),
        );
    }

    public function test_the_too_small_verdict_is_remembered_but_the_shipped_bytes_are_not(): void
    {
        $file = $this->setFavicon(32);
        $this->get($this->url(512))->assertOk();

        // Remembering only the verdict keeps a public request from re-reading the stored original,
        // while an upgrade that replaces the shipped icon still takes effect.
        Storage::disk('image_cache')->assertExists("{$file->name}/app-icon-512.unfit");
        Storage::disk('image_cache')->assertMissing("{$file->name}/app-icon-512.png");
    }

    /** @return array{int, int} */
    private function dimensionsOf(string $bytes): array
    {
        $size = getimagesizefromstring($bytes);
        $this->assertNotFalse($size);

        return [$size[0], $size[1]];
    }
}
