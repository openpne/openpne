<?php

namespace Tests\Feature\File;

use App\Files\FileStorage;
use App\Files\FileUploader;
use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * files.width / files.height: recorded at upload, and filled for older rows by
 * `openpne:backfill-image-dimensions`. Both paths are fail-open — a size that cannot be read
 * leaves NULL rather than failing the upload or the run (docs/internals/images.md).
 */
class ImageDimensionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_upload_records_the_pixel_size(): void
    {
        $file = $this->upload(UploadedFile::fake()->image('a.png', 240, 120));

        $this->assertSame(240, $file->width);
        $this->assertSame(120, $file->height);
    }

    public function test_an_upload_that_bypasses_the_stripper_records_the_pixel_size(): void
    {
        // gif never goes through the stripper, so the bytes are read from the upload rather than
        // from the stripped copy already in memory — a separate branch.
        $file = $this->upload(UploadedFile::fake()->createWithContent('a.gif', $this->fixture('tiny.gif')));

        $this->assertSame('image/gif', $file->type);
        $this->assertSame(6, $file->width);
        $this->assertSame(6, $file->height);
    }

    public function test_a_non_image_upload_records_no_size(): void
    {
        $file = $this->upload(UploadedFile::fake()->createWithContent('notes.txt', 'just text'));

        $this->assertStringStartsNotWith('image/', $file->type);
        $this->assertNull($file->width);
        $this->assertNull($file->height);
    }

    public function test_an_image_whose_size_cannot_be_read_still_uploads(): void
    {
        // A header-only webp: the type is read as an image, but the decode yields 0x0, which is no
        // size at all. Stripping is off because it is fail-closed on bytes like these, and what is
        // under test here is the size, not the strip.
        config(['openpne.images.strip_metadata' => false]);

        $file = $this->upload(UploadedFile::fake()->createWithContent('broken.webp', $this->headerOnlyWebp()));

        $this->assertSame('image/webp', $file->type);
        $this->assertNull($file->width);
        $this->assertNull($file->height);
    }

    public function test_the_backfill_fills_a_row_that_has_no_size(): void
    {
        $file = $this->stored('image/png', $this->pngBytes(320, 200));

        $this->backfill();

        $file->refresh();
        $this->assertSame(320, $file->width);
        $this->assertSame(200, $file->height);
    }

    public function test_the_backfill_leaves_a_non_image_row_alone(): void
    {
        // Real image bytes under a non-image type: only the filter keeps this row out, so a
        // regression that dropped it would show up as a filled size.
        $file = $this->stored('application/pdf', $this->pngBytes(320, 200));

        $this->backfill();

        $this->assertNull($file->refresh()->width);
    }

    public function test_the_backfill_leaves_undecodable_bytes_null_and_keeps_going(): void
    {
        $broken = $this->stored('image/png', 'not an image at all');
        $missing = File::factory()->create(['type' => 'image/png']);
        $good = $this->stored('image/png', $this->pngBytes(48, 24));

        $this->artisan('openpne:backfill-image-dimensions')
            ->expectsOutputToContain('Recorded dimensions for 1 file(s), skipped 2 unreadable one(s).')
            ->assertSuccessful();

        $this->assertNull($broken->refresh()->width);
        $this->assertNull($missing->refresh()->width);
        $this->assertSame(48, $good->refresh()->width);
    }

    public function test_the_backfill_does_not_touch_a_row_that_already_has_a_size(): void
    {
        // The NULL filter is what makes a re-run cheap and safe; without it this row would be
        // rewritten from its bytes.
        $file = $this->stored('image/png', $this->pngBytes(320, 200));
        $file->update(['width' => 10, 'height' => 5]);

        $this->backfill();

        $file->refresh();
        $this->assertSame(10, $file->width);
        $this->assertSame(5, $file->height);
    }

    private function upload(UploadedFile $upload): File
    {
        return app(FileUploader::class)->store($upload);
    }

    /** A File row whose bytes were written straight to storage, as an upgraded OpenPNE 3 file is. */
    private function stored(string $type, string $bytes): File
    {
        $file = File::factory()->create(['type' => $type, 'byte_size' => strlen($bytes)]);

        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $bytes);
        rewind($stream);
        app(FileStorage::class)->writeStream($file, $stream);
        fclose($stream);

        return $file;
    }

    private function backfill(): void
    {
        $this->artisan('openpne:backfill-image-dimensions')->assertSuccessful();
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/images/{$name}"));
    }

    private function pngBytes(int $width, int $height): string
    {
        return (string) UploadedFile::fake()->image('a.png', $width, $height)->get();
    }

    /** RIFF/WEBP container with nothing decodable in it: getimagesizefromstring reports 0x0. */
    private function headerOnlyWebp(): string
    {
        return 'RIFF'.pack('V', 100).'WEBPVP8 '.str_repeat("\x00", 90);
    }
}
