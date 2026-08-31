<?php

namespace Tests\Feature\File;

use App\Files\FileStorage;
use App\Files\FileUploader;
use App\Files\ImageCache;
use App\Files\ImageTransform;
use App\Models\AdminUser;
use App\Models\File;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Thumbnails and originals carry a validator, so a browser's copy is confirmed with a 304 instead of
 * the bytes again. The tag is computed, never read from storage, and only after the policy has let the
 * viewer through — a denied viewer learns nothing from a tag they happen to hold.
 */
class ConditionalDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('image_cache');
    }

    public function test_a_thumbnail_answers_a_matching_tag_with_304_and_no_bytes(): void
    {
        $owner = Member::factory()->create();
        $file = $this->avatar($owner);
        $url = $file->thumbnailUrl(120, 120, square: true);

        $first = $this->actingAs($owner)->get($url);
        $first->assertOk();
        $etag = (string) $first->headers->get('ETag');
        $this->assertSame(ImageTransform::fromGeometry('w120_h120_sq')->etag($file->name, 'png'), $etag);
        $this->assertCacheControl($first, ['private' => true, 'max-age' => '86400']);

        // Nothing is read for a match: the tag is computed, not derived from the bytes.
        $this->mock(ImageCache::class)->shouldNotReceive('bytes');
        $again = $this->withHeader('If-None-Match', $etag)->get($url);
        $again->assertStatus(304);
        $this->assertSame('', $again->getContent());
        $this->assertSame($etag, $again->headers->get('ETag'));
        $this->assertCacheControl($again, ['private' => true, 'max-age' => '86400']);
        $this->forgetMock(ImageCache::class);

        $stale = $this->withHeader('If-None-Match', '"stale"')->get($url);
        $stale->assertOk();
        $this->assertNotSame('', $stale->getContent());
    }

    public function test_the_thumbnail_tag_differs_by_geometry(): void
    {
        $owner = Member::factory()->create();
        $file = $this->avatar($owner);

        $square = $this->actingAs($owner)->get($file->thumbnailUrl(120, 120, square: true))->assertOk()->headers->get('ETag');
        $fit = $this->get($file->thumbnailUrl(120, 120))->assertOk()->headers->get('ETag');

        $this->assertNotSame($square, $fit);
    }

    public function test_an_original_answers_a_matching_tag_with_304_and_no_bytes(): void
    {
        $owner = Member::factory()->create();
        $file = $this->memberImage($owner, 'PNGDATA');
        $url = route('file.show', $file->name);

        $first = $this->actingAs($owner)->get($url);
        $first->assertOk();
        $this->assertSame('PNGDATA', $first->streamedContent());
        $etag = (string) $first->headers->get('ETag');
        $this->assertSame('"'.$file->name.'"', $etag);
        $this->assertCacheControl($first, ['private' => true, 'max-age' => '0', 'must-revalidate' => true]);

        // The store is not opened for a match.
        $this->mock(FileStorage::class)->shouldNotReceive('readStream');
        $again = $this->withHeader('If-None-Match', $etag)->get($url);
        $again->assertStatus(304);
        $this->assertSame('', $again->getContent());
        $this->assertSame($etag, $again->headers->get('ETag'));
        $this->assertCacheControl($again, ['private' => true, 'max-age' => '0', 'must-revalidate' => true]);
        $this->forgetMock(FileStorage::class);

        $stale = $this->withHeader('If-None-Match', '"stale"')->get($url);
        $stale->assertOk();
        $this->assertSame('PNGDATA', $stale->streamedContent());
    }

    public function test_a_denied_viewer_holding_the_tag_is_answered_404_not_304(): void
    {
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        $owner->blocksMade()->attach($viewer, ['created_at' => now()]);
        $thumbnail = $this->avatar($owner);
        $original = $this->memberImage($owner, 'secret');

        $this->actingAs($viewer)
            ->withHeader('If-None-Match', ImageTransform::fromGeometry('w120_h120_sq')->etag($thumbnail->name, 'png'))
            ->get($thumbnail->thumbnailUrl(120, 120, square: true))
            ->assertNotFound();

        $this->actingAs($viewer)
            ->withHeader('If-None-Match', '"'.$original->name.'"')
            ->get(route('file.show', $original->name))
            ->assertNotFound();
    }

    public function test_the_admin_raw_route_answers_a_matching_tag_with_304_without_opening_the_store(): void
    {
        $file = $this->memberImage(Member::factory()->create(), 'PNGDATA');
        $url = route('admin.file.raw', ['file' => $file->name]);
        $this->actingAs(AdminUser::factory()->create(), 'admin');

        $first = $this->get($url);
        $first->assertOk();
        $this->assertSame('"'.$file->name.'"', $first->headers->get('ETag'));

        $storage = $this->mock(FileStorage::class);
        $storage->shouldReceive('exists')->andReturn(true);
        $storage->shouldNotReceive('readStream');
        $again = $this->withHeader('If-None-Match', '"'.$file->name.'"')->get($url);
        $again->assertStatus(304);
        $this->assertSame('', $again->getContent());
        $this->assertCacheControl($again, ['private' => true, 'max-age' => '0', 'must-revalidate' => true]);
        $this->forgetMock(FileStorage::class);

        $stale = $this->withHeader('If-None-Match', '"stale"')->get($url);
        $stale->assertOk();
        $this->assertSame('PNGDATA', $stale->streamedContent());
    }

    public function test_a_file_whose_bytes_are_gone_is_404_on_the_raw_route_even_with_the_tag(): void
    {
        // The existence check sits ahead of the 304 too: a row without bytes must not have a browser
        // keep drawing what the server no longer holds.
        $file = $this->memberImage(Member::factory()->create(), 'PNGDATA');
        $this->actingAs(AdminUser::factory()->create(), 'admin');
        $this->mock(FileStorage::class)->shouldReceive('exists')->andReturn(false);

        $this->withHeader('If-None-Match', '"'.$file->name.'"')
            ->get(route('admin.file.raw', ['file' => $file->name]))
            ->assertNotFound();
    }

    public function test_a_non_admin_holding_the_tag_is_answered_404_not_304_on_the_raw_route(): void
    {
        // The route bypasses FilePolicy, so its own gate has to sit ahead of the 304: otherwise a
        // guest with a token could tell an existing file (304) from a missing one (404).
        $file = $this->memberImage(Member::factory()->create(), 'PNGDATA');

        $this->withHeader('If-None-Match', '"'.$file->name.'"')
            ->get(route('admin.file.raw', ['file' => $file->name]))
            ->assertNotFound();
    }

    /** @param  array<string, string|true>  $directives */
    private function assertCacheControl(TestResponse $response, array $directives): void
    {
        foreach ($directives as $directive => $value) {
            $this->assertTrue($response->headers->hasCacheControlDirective($directive), $directive);
            if ($value !== true) {
                $this->assertSame($value, $response->headers->getCacheControlDirective($directive), $directive);
            }
        }
    }

    private function avatar(Member $owner): File
    {
        return app(FileUploader::class)->store(UploadedFile::fake()->image('a.png', 240, 120), 'member', (int) $owner->getKey());
    }

    private function memberImage(Member $owner, string $bytes): File
    {
        $file = File::factory()->create([
            'type' => 'image/png',
            'related_entity_type' => 'member',
            'related_entity_id' => $owner->getKey(),
            'byte_size' => strlen($bytes),
        ]);

        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $bytes);
        rewind($stream);
        app(FileStorage::class)->writeStream($file, $stream);
        fclose($stream);

        return $file;
    }
}
