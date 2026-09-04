<?php

declare(strict_types=1);

namespace Tests\Feature\File;

use App\Files\FileStorage;
use App\Files\FileUploader;
use App\Models\BannerImage;
use App\Models\File;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicResponseCookiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('image_cache');
    }

    public function test_a_public_asset_is_served_without_session_cookies(): void
    {
        $file = $this->publicAsset();

        $response = $this->get(route('file.public', ['file' => $file->name]));

        $response->assertOk();
        $this->assertPublicAndCookieFree($response);
    }

    public function test_a_banner_image_is_served_without_session_cookies(): void
    {
        $file = $this->bannerImage();

        $response = $this->get(route('banner.image', ['file' => $file->name]));

        $response->assertOk();
        $this->assertPublicAndCookieFree($response);
    }

    public function test_an_authenticated_viewer_gets_no_cookies_either(): void
    {
        // The cookies are attached on the way out regardless of who asked, so the authenticated
        // case is the one that would otherwise put a real session into a shared cache.
        $file = $this->publicAsset();

        $response = $this->actingAs(Member::factory()->create())
            ->get(route('file.public', ['file' => $file->name]));

        $response->assertOk();
        $this->assertPublicAndCookieFree($response);
    }

    public function test_a_head_request_is_cookie_free_too(): void
    {
        $file = $this->publicAsset();

        $response = $this->head(route('file.public', ['file' => $file->name]));

        $response->assertOk();
        $this->assertPublicAndCookieFree($response);
    }

    public function test_a_public_response_outside_the_delivery_routes_keeps_its_cookies(): void
    {
        // The route is synthetic rather than one of the app's own, so making those cacheable later
        // does not have to fight this test.
        Route::middleware('web')->get('/__public-probe', fn () => response('ok', 200, [
            'Cache-Control' => 'public, max-age=86400',
        ]));

        $response = $this->get('/__public-probe');

        $response->assertOk();
        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertNotEmpty($response->headers->getCookies());
    }

    public function test_a_private_response_keeps_its_cookies(): void
    {
        $owner = Member::factory()->create();
        $file = app(FileUploader::class)->store(
            UploadedFile::fake()->image('a.png', 40, 40),
            'member',
            (int) $owner->getKey(),
        );

        $response = $this->actingAs($owner)->get(route('file.show', ['file' => $file->name]));

        $response->assertOk();
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertNotEmpty($response->headers->getCookies());
    }

    private function assertPublicAndCookieFree(mixed $response): void
    {
        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertSame([], $response->headers->getCookies());
        $this->assertNull($response->headers->get('Set-Cookie'));
        $this->assertStringNotContainsStringIgnoringCase('cookie', (string) $response->headers->get('Vary'));
    }

    private function publicAsset(): File
    {
        return $this->fileWithBytes([
            'type' => 'image/png',
            'explicit_visibility' => File::VISIBILITY_PUBLIC,
            'related_entity_type' => null,
            'related_entity_id' => null,
        ]);
    }

    private function bannerImage(): File
    {
        $banner = BannerImage::factory()->create();

        return $this->fileWithBytes([
            'type' => 'image/png',
            'related_entity_type' => 'bannerImage',
            'related_entity_id' => $banner->getKey(),
        ]);
    }

    private function fileWithBytes(array $attributes): File
    {
        $content = 'PNGDATA';
        $file = File::factory()->create($attributes + ['byte_size' => strlen($content)]);

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);
        app(FileStorage::class)->writeStream($file, $stream);
        fclose($stream);

        return $file;
    }
}
