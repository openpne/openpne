<?php

namespace Tests\Feature\File;

use App\Files\FileStorage;
use App\Files\ImageBytesOverLimitException;
use App\Files\ImageCache;
use App\Files\ImageTransform;
use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\CountedByteStream;
use Tests\Fixtures\CountingFileStorage;
use Tests\TestCase;

/**
 * A caller with a byte budget never holds more than it could answer with: ImageCache stops reading
 * one byte past the budget rather than reading a file whole and measuring afterwards. The source
 * here is generated as it is read, so the bound is asserted on bytes actually taken — a read that
 * ignored it would be visible in the count and not only in the memory it cost.
 */
class ImageCacheBoundedReadTest extends TestCase
{
    use RefreshDatabase;

    /** What the storage really yields: far past any budget below, so an unbounded read is unmistakable. */
    private const STORED = 32 * 1024 * 1024;

    private const BUDGET = 64 * 1024;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('image_cache');
    }

    /** A row that understates itself: byte_size says 1 KB, the storage yields STORED bytes. */
    private function understated(): File
    {
        $file = File::factory()->create(['type' => 'image/png', 'byte_size' => 1024]);

        CountedByteStream::prepare(self::STORED);
        $this->app->instance(
            FileStorage::class,
            new CountingFileStorage(app(FileStorage::class), (int) $file->getKey()),
        );

        return $file;
    }

    private function assertReadStoppedAtTheBudget(): void
    {
        $this->assertLessThanOrEqual(
            self::BUDGET + 1 + CountedByteStream::SLACK,
            CountedByteStream::consumed(),
            'The whole file was read before its size was judged.',
        );
    }

    private function bytes(File $file, string $geometry, ?int $maxBytes): string
    {
        return app(ImageCache::class)->bytes($file, ImageTransform::fromGeometry($geometry), 'png', $maxBytes);
    }

    public function test_the_original_size_is_refused_without_reading_past_the_budget(): void
    {
        $file = $this->understated();

        $this->assertThrows(
            fn () => $this->bytes($file, 'w_h', self::BUDGET),
            ImageBytesOverLimitException::class,
        );

        $this->assertReadStoppedAtTheBudget();
    }

    public function test_a_thumbnail_refuses_an_oversized_source_before_it_reaches_the_decoder(): void
    {
        $file = $this->understated();

        // These bytes are not an image, so a decoder that saw them would fail as one instead.
        $this->assertThrows(
            fn () => $this->bytes($file, 'w640_h640', self::BUDGET),
            ImageBytesOverLimitException::class,
        );

        $this->assertReadStoppedAtTheBudget();
        $this->assertSame([], Storage::disk('image_cache')->allFiles());
    }

    public function test_a_file_inside_the_budget_is_read_whole_and_an_absent_budget_reads_everything(): void
    {
        $stored = random_bytes(2048);
        $file = File::factory()->create(['type' => 'image/png', 'byte_size' => strlen($stored)]);
        $this->write($file, $stored);

        // The budget is a ceiling, not a target: a file exactly at it fits, one byte under it does not.
        $this->assertSame($stored, $this->bytes($file, 'w_h', strlen($stored)));
        $this->assertSame($stored, $this->bytes($file, 'w_h', null));
        $this->assertThrows(
            fn () => $this->bytes($file, 'w_h', strlen($stored) - 1),
            ImageBytesOverLimitException::class,
        );
    }

    private function write(File $file, string $bytes): void
    {
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $bytes);
        rewind($stream);
        app(FileStorage::class)->writeStream($file, $stream);
        fclose($stream);
    }
}
